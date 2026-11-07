<?php

declare(strict_types=1);

use Cboxdk\StatamicMcp\Actions\ActionDefinition;
use Cboxdk\StatamicMcp\Actions\ActionEffect;
use Cboxdk\StatamicMcp\Actions\Contracts\ActionHandler;
use Cboxdk\StatamicMcp\Actions\Contracts\ActionPolicy;
use Cboxdk\StatamicMcp\Actions\Contracts\OperationStore;
use Cboxdk\StatamicMcp\Actions\ExecutionContext;
use Cboxdk\StatamicMcp\Actions\Operations\DatabaseOperationStore;
use Cboxdk\StatamicMcp\Actions\Operations\FileOperationStore;
use Cboxdk\StatamicMcp\Actions\Operations\Operation;
use Cboxdk\StatamicMcp\Actions\Operations\OperationCoordinator;
use Cboxdk\StatamicMcp\Actions\Operations\OperationState;
use Illuminate\Contracts\Encryption\Encrypter;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

beforeEach(function () {
    $this->directory = sys_get_temp_dir() . '/mcp-intent-' . bin2hex(random_bytes(8));
    $this->migration = require __DIR__ . '/../../../database/migrations/operations/0001_create_mcp_operations_table.php';
    $this->migration->up();
    $this->action = new ActionDefinition('fixture/recovery', 'edit', 3, 'Edit content.', ActionEffect::Edit,
        ['fixture:edit'], ['type' => 'object'], ['type' => 'object'], IntentHandler::class, IntentPolicy::class);
    $this->context = new ExecutionContext('private-actor', 'stable-grant', 'default', 'editor', 'http',
        'prepare', hrtime(true) + 10_000_000_000, 'credential-never-store');
});

afterEach(function () {
    $this->migration->down();
    File::deleteDirectory($this->directory);
});

it('persists encrypted bounded recovery identity before invoking a handler', function (string $driver) {
    $store = $driver === 'file' ? new FileOperationStore($this->directory) : new DatabaseOperationStore(DB::connection());
    $coordinator = new OperationCoordinator($store, app(Encrypter::class));
    $input = ['secret' => 'raw-content-never-store', 'file' => base64_encode(str_repeat('a', 10 * 1024 * 1024))];
    $prepared = $coordinator->prepare($this->action, $input, $this->context);
    $stored = $store->find($prepared->id);
    $intent = $coordinator->intent($stored);
    expect($intent->actorId)->toBe('private-actor')->and($intent->grantId)->toBe('stable-grant')
        ->and($intent->actionId)->toBe($this->action->id())->and($intent->actionVersion)->toBe(3)
        ->and($intent->site)->toBe('default')->and($intent->effect)->toBe(ActionEffect::Edit)
        ->and($intent->inputDigest)->toHaveLength(64)->and($intent->operationId)->toBe($prepared->id);
    expect(strlen($stored->encryptedIntent))->toBeLessThan(8192);
    $serialized = json_encode($stored->toArray(), JSON_THROW_ON_ERROR);
    foreach (['private-actor', 'stable-grant', 'fixture/recovery', 'raw-content-never-store', 'credential-never-store'] as $private) {
        expect($serialized)->not->toContain($private);
    }
    expect($intent->toJson())->not->toContain('raw-content-never-store')->not->toContain('credential-never-store');
    $context = $this->context->withOperationId($prepared->id);
    $calls = 0;
    $write = function () use (&$calls): array {
        $calls++;
        throw new RuntimeException('Transport lost after a side effect.');
    };
    expect(fn () => $coordinator->execute($this->action, $input, $context, $write))->toThrow(RuntimeException::class);
    $unknown = $store->find($prepared->id);
    expect($unknown->state)->toBe(OperationState::OutcomeUnknown)
        ->and($coordinator->intent($unknown)->toJson())->toBe($intent->toJson());
    expect(fn () => $coordinator->execute($this->action, $input, $context, $write))->toThrow(RuntimeException::class);
    expect($calls)->toBe(1);
})->with(['file', 'database']);

it('refuses intent replacement during a state transition and cross-operation ciphertext reuse', function (string $driver) {
    $store = $driver === 'file' ? new FileOperationStore($this->directory) : new DatabaseOperationStore(DB::connection());
    $coordinator = new OperationCoordinator($store, app(Encrypter::class));
    $first = $coordinator->prepare($this->action, ['title' => 'same'], $this->context);
    $second = $coordinator->prepare($this->action, ['title' => 'same'], $this->context);
    $forged = new Operation($first->id, $first->bindingDigest, OperationState::Executing,
        $first->version + 1, $first->expiresAt, encryptedIntent: $second->encryptedIntent);
    expect(fn () => $store->transition($first, $forged))->toThrow(InvalidArgumentException::class);
    expect(fn () => $coordinator->intent($forged))->toThrow(RuntimeException::class);
    expect($store->find($first->id)->state)->toBe(OperationState::Ready);
})->with(['file', 'database']);

it('fails before reserving an operation when recovery metadata cannot be encrypted', function () {
    $store = new FileOperationStore($this->directory);
    $encrypter = Mockery::mock(Encrypter::class);
    $encrypter->shouldReceive('encrypt')->once()->andThrow(new RuntimeException('Encryption unavailable.'));
    $coordinator = new OperationCoordinator($store, $encrypter);
    expect(fn () => $coordinator->prepare($this->action, [], $this->context))->toThrow(RuntimeException::class);
    expect($store->reconcileCapacity())->toBe(0);
});

it('never claims operations whose encrypted recovery identity is missing or damaged', function (string $driver, bool $missing) {
    $store = $driver === 'file' ? new FileOperationStore($this->directory) : new DatabaseOperationStore(DB::connection());
    $coordinator = new OperationCoordinator($store, app(Encrypter::class));
    $operation = $coordinator->prepare($this->action, [], $this->context);
    $data = $operation->toArray();
    $data['intent'] = $missing ? null : 'tampered';
    $json = json_encode($data, JSON_THROW_ON_ERROR);
    if ($driver === 'file') {
        file_put_contents($this->directory . '/' . $operation->id . '.json', $json);
    } else {
        DB::table('mcp_operations')->where('id', $operation->id)->update(['payload' => $json]);
    }
    $calls = 0;
    $write = function () use (&$calls): array {
        $calls++;

        return [];
    };
    expect(fn () => $coordinator->execute($this->action, [], $this->context->withOperationId($operation->id), $write))->toThrow(RuntimeException::class);
    expect($calls)->toBe(0)->and($store->find($operation->id)->state)->toBe(OperationState::Ready);
})->with(['file', 'database'])->with([true, false]);

it('inspects recovery identity without leaking content or changing an unknown outcome', function () {
    $store = new FileOperationStore($this->directory);
    $this->app->instance(OperationStore::class, $store);
    $coordinator = app(OperationCoordinator::class);
    $operation = $coordinator->prepare($this->action, ['private' => 'sensitive content'], $this->context);
    $executing = $operation->transition(OperationState::Executing);
    $store->transition($operation, $executing);
    $unknown = $executing->transition(OperationState::OutcomeUnknown);
    $store->transition($executing, $unknown);
    expect(Artisan::call('mcp:inspect-operation', ['id' => $operation->id]))->toBe(0);
    $output = Artisan::output();
    $data = json_decode($output, true, flags: JSON_THROW_ON_ERROR);
    expect($data['state'])->toBe('outcome_unknown')->and($data['actor_id'])->toBe('private-actor')
        ->and($data['action_id'])->toBe($this->action->id());
    expect($output)->not->toContain('sensitive content')->not->toContain('credential-never-store');
    expect($store->find($operation->id)->toArray())->toBe($unknown->toArray());
    $this->artisan('mcp:inspect-operation', ['id' => '../invalid'])->assertExitCode(2);
    $this->artisan('mcp:inspect-operation', ['id' => str_repeat('f', 64)])
        ->expectsOutputToContain('Absence is not proof')->assertFailed();
});

final class IntentHandler implements ActionHandler
{
    public function execute(array $input, ExecutionContext $context): array
    {
        return [];
    }
}

final class IntentPolicy implements ActionPolicy
{
    public function authorize(array $input, ExecutionContext $context): void {}

    public function filterResult(array $input, array $result, ExecutionContext $context): array
    {
        return $result;
    }
}

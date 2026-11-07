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
use Cboxdk\StatamicMcp\Actions\Operations\OperationRecovery;
use Cboxdk\StatamicMcp\Actions\Operations\OperationState;
use Cboxdk\StatamicMcp\Contracts\AuditStore;
use Cboxdk\StatamicMcp\Contracts\DurableAuditStore;
use Illuminate\Contracts\Encryption\Encrypter;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

beforeEach(function () {
    $this->directory = sys_get_temp_dir() . '/mcp-recovery-' . bin2hex(random_bytes(8));
    mkdir($this->directory, 0700, true);
    $this->migration = require __DIR__ . '/../../../database/migrations/operations/0001_create_mcp_operations_table.php';
    $this->migration->up();
    $this->action = new ActionDefinition('fixture/recovery', 'edit', 1, 'Edit content.', ActionEffect::Edit,
        ['fixture:edit'], ['type' => 'object'], ['type' => 'object'], RecoveryHandler::class, RecoveryPolicy::class);
    $this->context = new ExecutionContext('actor', 'grant', 'default', 'editor', 'http', 'prepare', hrtime(true) + 10_000_000_000);
    $this->audit = Mockery::mock(DurableAuditStore::class);
    $this->audit->shouldReceive('writeDurable')->byDefault();
});

afterEach(function () {
    $this->migration->down();
    File::deleteDirectory($this->directory);
});

it('records an encrypted operator report without replaying a write and retains it for thirty days', function (string $driver) {
    $store = $driver === 'file' ? new FileOperationStore($this->directory) : new DatabaseOperationStore(DB::connection());
    $coordinator = new OperationCoordinator($store, app(Encrypter::class));
    $recovery = new OperationRecovery($store, $coordinator, app(Encrypter::class), $this->audit);
    $prepared = $coordinator->prepare($this->action, ['title' => 'private content'], $this->context);
    $context = $this->context->withOperationId($prepared->id);
    $calls = 0;
    $write = function () use (&$calls): array {
        $calls++;
        throw new RuntimeException('Lost response after persisting.');
    };
    expect(fn () => $coordinator->execute($this->action, ['title' => 'private content'], $context, $write))->toThrow(RuntimeException::class);
    $unknown = $store->find($prepared->id);
    $this->travel(2)->days();
    $evidence = 'Verified private content, URL purge and external queue settlement.';
    $this->audit->shouldReceive('writeDurable')->once()->withArgs(function (array $record) use ($evidence): bool {
        return $record['metadata']['reported_outcome'] === 'committed' && ! str_contains(json_encode($record), $evidence);
    });
    $closed = $recovery->reconcile($unknown->id, $unknown->version, 'local operator', 'committed', $evidence);
    expect($closed->state)->toBe(OperationState::Reconciled)->and($calls)->toBe(1)
        ->and($closed->expiresAt)->toBe(now()->addDays(30)->getTimestamp());
    expect(json_encode($closed->toArray()))->not->toContain('private content')->not->toContain('local operator');
    $report = $recovery->report($store->find($closed->id));
    expect($report->outcome)->toBe('committed')->and($report->evidence)->toBe($evidence)
        ->and($report->previousVersion)->toBe($unknown->version);
    expect(fn () => $coordinator->execute($this->action, ['title' => 'private content'], $context, $write))->toThrow(RuntimeException::class);
    expect(fn () => $recovery->reconcile($unknown->id, $unknown->version, 'other', 'no_effect', 'New assertion'))->toThrow(RuntimeException::class);
    expect(fn () => $closed->transition(OperationState::Ready))->toThrow(LogicException::class);
    expect($store->prune())->toBe(0);
    $this->travel(30)->days();
    expect($store->prune())->toBe(1)->and($store->find($closed->id))->toBeNull();
    expect(fn () => $coordinator->execute($this->action, ['title' => 'private content'], $context, $write))->toThrow(RuntimeException::class);
    expect($calls)->toBe(1);
})->with(['file', 'database']);

it('does not release an executing operation or acknowledge failed recovery audit', function (string $driver) {
    $store = $driver === 'file' ? new FileOperationStore($this->directory) : new DatabaseOperationStore(DB::connection());
    $coordinator = new OperationCoordinator($store, app(Encrypter::class));
    $recovery = new OperationRecovery($store, $coordinator, app(Encrypter::class), $this->audit);
    $ready = $coordinator->prepare($this->action, [], $this->context);
    $running = $ready->transition(OperationState::Executing);
    $store->transition($ready, $running);
    expect(fn () => $recovery->reconcile($running->id, $running->version, 'operator', 'partial', 'Evidence'))
        ->toThrow(RuntimeException::class, 'Executing operations remain reserved');
    expect($store->find($running->id)->state)->toBe(OperationState::Executing);
    $unknown = $running->transition(OperationState::OutcomeUnknown);
    $store->transition($running, $unknown);
    $this->audit->shouldReceive('writeDurable')->once()->andThrow(new RuntimeException('Audit unavailable.'));
    expect(fn () => $recovery->reconcile($unknown->id, $unknown->version, 'operator', 'partial', 'Evidence'))->toThrow(RuntimeException::class);
    expect($store->find($unknown->id)->toArray())->toBe($unknown->toArray());
})->with(['file', 'database']);

it('refuses ciphertext reuse and keeps the first successful reconciliation immutable', function (string $driver) {
    $store = $driver === 'file' ? new FileOperationStore($this->directory) : new DatabaseOperationStore(DB::connection());
    $coordinator = new OperationCoordinator($store, app(Encrypter::class));
    $recovery = new OperationRecovery($store, $coordinator, app(Encrypter::class), $this->audit);
    $unknowns = [];
    foreach ([1, 2] as $number) {
        $ready = $coordinator->prepare($this->action, ['number' => $number], $this->context);
        $running = $ready->transition(OperationState::Executing);
        $store->transition($ready, $running);
        $unknown = $running->transition(OperationState::OutcomeUnknown);
        $store->transition($running, $unknown);
        $unknowns[] = $unknown;
    }
    $first = $recovery->reconcile($unknowns[0]->id, $unknowns[0]->version, 'operator', 'no_effect', 'Verified storage unchanged.');
    $forged = $unknowns[1]->transition(OperationState::Reconciled, encryptedReconciliation: $first->encryptedReconciliation, retainUntil: $first->expiresAt);
    expect(fn () => $recovery->report($forged))->toThrow(RuntimeException::class, 'does not belong');
    expect($store->transition($unknowns[0], $first))->toBeFalse();
    expect(fn () => $store->transition($first, new Operation($first->id, $first->bindingDigest, OperationState::Reconciled,
        $first->version + 1, $first->expiresAt, encryptedIntent: $first->encryptedIntent, encryptedReconciliation: 'replacement')))->toThrow(InvalidArgumentException::class);
})->with(['file', 'database']);

it('makes CLI recovery read-only by default and does not expose evidence during inspection', function () {
    $store = new FileOperationStore($this->directory);
    $this->app->instance(OperationStore::class, $store);
    $this->app->instance(AuditStore::class, $this->audit);
    $coordinator = app(OperationCoordinator::class);
    $ready = $coordinator->prepare($this->action, [], $this->context);
    $running = $ready->transition(OperationState::Executing);
    $store->transition($ready, $running);
    $unknown = $running->transition(OperationState::OutcomeUnknown);
    $store->transition($running, $unknown);
    $path = $this->directory . '/evidence.txt';
    file_put_contents($path, 'Private investigation evidence.');
    $args = ['id' => $unknown->id, '--expected-version' => (string) $unknown->version,
        '--reported-by' => 'operator', '--outcome' => 'partial', '--evidence-file' => $path];
    $this->artisan('mcp:reconcile-operation', $args)->expectsOutputToContain('Dry run')->assertSuccessful();
    expect($store->find($unknown->id)->toArray())->toBe($unknown->toArray());
    $this->artisan('mcp:reconcile-operation', [...$args, '--apply' => true])->assertSuccessful();
    expect(Artisan::call('mcp:inspect-operation', ['id' => $unknown->id]))->toBe(0);
    $output = Artisan::output();
    expect($output)->not->toContain('Private investigation evidence.');
    $data = json_decode($output, true, flags: JSON_THROW_ON_ERROR);
    expect($data['reconciliation']['reported_outcome'])->toBe('partial')->and($data)->toHaveKey('retained_until');
    $this->artisan('mcp:reconcile-operation', [...$args, '--apply' => true])->assertFailed();
    file_put_contents($path, str_repeat('a', 12001));
    $this->artisan('mcp:reconcile-operation', $args)->assertExitCode(2);
});

final class RecoveryHandler implements ActionHandler
{
    public function execute(array $input, ExecutionContext $context): array
    {
        return [];
    }
}

final class RecoveryPolicy implements ActionPolicy
{
    public function authorize(array $input, ExecutionContext $context): void {}

    public function filterResult(array $input, array $result, ExecutionContext $context): array
    {
        return $result;
    }
}

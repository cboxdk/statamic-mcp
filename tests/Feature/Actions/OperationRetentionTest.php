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
use Cboxdk\StatamicMcp\Actions\SchemaValidator;
use Illuminate\Contracts\Encryption\Encrypter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\InputStream;
use Symfony\Component\Process\Process;

beforeEach(function () {
    $this->directory = sys_get_temp_dir() . '/mcp-retention-' . bin2hex(random_bytes(8));
    $this->migration = require __DIR__ . '/../../../database/migrations/operations/0001_create_mcp_operations_table.php';
    $this->migration->up();
});

afterEach(function () {
    $this->migration->down();
    File::deleteDirectory($this->directory);
});

it('bounds admission and retains uncertain outcomes during expiry cleanup', function (string $driver) {
    $store = $driver === 'file' ? new FileOperationStore($this->directory, 2) : new DatabaseOperationStore(DB::connection(), 2);
    $first = new Operation(bin2hex(random_bytes(32)), hash('sha256', 'binding'), OperationState::Prepared, 1, now()->addMinute()->getTimestamp());
    $second = new Operation(bin2hex(random_bytes(32)), hash('sha256', 'binding'), OperationState::Prepared, 1, now()->addMinute()->getTimestamp());
    $third = new Operation(bin2hex(random_bytes(32)), hash('sha256', 'binding'), OperationState::Prepared, 1, now()->addHour()->getTimestamp());
    expect($store->create($first))->toBeTrue();
    expect($store->create($second))->toBeTrue();
    expect($store->create($first))->toBeFalse();
    expect(fn () => $store->create($third))->toThrow(RuntimeException::class);
    $ready = $second->transition(OperationState::Ready);
    $store->transition($second, $ready);
    $executing = $ready->transition(OperationState::Executing);
    $store->transition($ready, $executing);
    $this->travel(61)->seconds();
    expect($store->prune())->toBe(1);
    expect($store->find($first->id))->toBeNull();
    expect($store->find($second->id)->state)->toBe(OperationState::Executing);
    expect($store->create($third))->toBeTrue();
    $unknown = $executing->transition(OperationState::OutcomeUnknown);
    $store->transition($executing, $unknown);
    expect($store->prune())->toBe(0);
    expect($store->find($second->id)->state)->toBe(OperationState::OutcomeUnknown);
})->with(['file', 'database']);

it('never recreates a pruned write and binds handles to actor grant and input', function (string $driver) {
    $store = $driver === 'file' ? new FileOperationStore($this->directory) : new DatabaseOperationStore(DB::connection());
    $coordinator = new OperationCoordinator($store, app(Encrypter::class));
    $action = new ActionDefinition('fixture/retention', 'edit', 1, 'Edit a draft.', ActionEffect::Edit, ['fixture:edit'],
        ['type' => 'object'], ['type' => 'object'], RetentionHandler::class, RetentionPolicy::class);
    $context = new ExecutionContext('actor', 'grant', 'default', 'editor', 'http', 'prepare', hrtime(true) + 10_000_000_000);
    $calls = 0;
    $write = function () use (&$calls): array {
        $calls++;

        return ['ok' => true];
    };
    $prepared = $coordinator->prepare($action, ['title' => 'A'], $context);
    expect($calls)->toBe(0);
    $context = $context->withOperationId($prepared->id);
    foreach ([
        new ExecutionContext('other', 'grant', 'default', 'editor', 'http', $prepared->id, hrtime(true) + 10_000_000_000),
        new ExecutionContext('actor', 'other', 'default', 'editor', 'http', $prepared->id, hrtime(true) + 10_000_000_000),
    ] as $wrong) {
        expect(fn () => $coordinator->execute($action, ['title' => 'A'], $wrong, $write))->toThrow(RuntimeException::class);
    }
    expect(fn () => $coordinator->execute($action, ['title' => 'B'], $context, $write))->toThrow(RuntimeException::class);
    expect($coordinator->execute($action, ['title' => 'A'], $context, $write)->replayed)->toBeFalse();
    expect($coordinator->execute($action, ['title' => 'A'], $context, $write)->replayed)->toBeTrue();
    expect($calls)->toBe(1);
    $this->travel(3601)->seconds();
    expect($store->prune())->toBe(1);
    expect(fn () => $coordinator->execute($action, ['title' => 'A'], $context, $write))->toThrow(RuntimeException::class);
    expect($calls)->toBe(1);
})->with(['file', 'database']);

it('keeps filesystem artifacts bounded for arbitrary unknown handle lookups', function () {
    $store = new FileOperationStore($this->directory);
    for ($i = 0; $i < 100; $i++) {
        expect($store->find(bin2hex(random_bytes(32))))->toBeNull();
    }
    expect(iterator_count(new FilesystemIterator($this->directory)))->toBe(1);
});

final class RetentionHandler implements ActionHandler
{
    public function execute(array $input, ExecutionContext $context): array
    {
        return [];
    }
}

final class RetentionPolicy implements ActionPolicy
{
    public function authorize(array $input, ExecutionContext $context): void {}

    public function filterResult(array $input, array $result, ExecutionContext $context): array
    {
        return $result;
    }
}

it('reserves the last capacity slot atomically across processes', function (string $driver) {
    File::makeDirectory($this->directory, 0700, true);
    $path = $this->directory;
    if ($driver === 'database') {
        $path .= '/admission.sqlite';
        $pdo = new PDO('sqlite:' . $path);
        $pdo->exec('CREATE TABLE mcp_operations (id VARCHAR(64) PRIMARY KEY, version INTEGER, payload TEXT, state VARCHAR(32), expires_at INTEGER)');
        $pdo->exec('CREATE TABLE mcp_operation_capacity (id INTEGER PRIMARY KEY, record_count INTEGER)');
        $pdo->exec('INSERT INTO mcp_operation_capacity VALUES (1, 0)');
    }
    $command = [PHP_BINARY, __DIR__ . '/../../Support/operation-admission.php', $driver, $path];
    $inputs = [new InputStream, new InputStream];
    $workers = [new Process($command), new Process($command)];
    try {
        foreach ($workers as $index => $worker) {
            $worker->setInput($inputs[$index])->setTimeout(10)->start();
        }
        foreach ($workers as $worker) {
            expect($worker->waitUntil(fn () => str_contains($worker->getOutput(), "ready\n")))->toBeTrue();
        }
        foreach ($inputs as $input) {
            $input->write("create\n");
            $input->close();
        }
        foreach ($workers as $worker) {
            $worker->wait();
            expect($worker->getExitCode())->toBe(0);
        }
        $outputs = array_map(fn ($worker) => $worker->getOutput(), $workers);
        sort($outputs);
        expect($outputs)->toBe(["ready\nadmitted", "ready\nfull"]);
    } finally {
        foreach ($workers as $worker) {
            $worker->stop();
        }
    }
})->with(['file', 'database']);

it('wires storage and pruning while refusing local files on multiple nodes', function () {
    config()->set('statamic.mcp.storage.operations_path', $this->directory);
    $store = app(OperationStore::class);
    expect($store)->toBeInstanceOf(FileOperationStore::class);
    $expired = new Operation(bin2hex(random_bytes(32)), hash('sha256', 'binding'), OperationState::Prepared, 1, now()->subMinute()->getTimestamp());
    $store->create($expired);
    $this->artisan('mcp:prune-operations')->expectsOutputToContain('Pruned 1')->assertSuccessful();
    $this->artisan('mcp:prune-operations', ['--limit' => '-1'])->assertExitCode(2);
    config()->set('statamic.mcp.operations.multi_node', true);
    expect(fn () => app(OperationStore::class))->toThrow(LogicException::class);
    config()->set('statamic.mcp.stores.operations', DatabaseOperationStore::class);
    expect(app(OperationStore::class))->toBeInstanceOf(DatabaseOperationStore::class);
});

it('repairs conservative crash reservations without reopening uncertain writes', function (string $driver) {
    $store = $driver === 'file' ? new FileOperationStore($this->directory, 2) : new DatabaseOperationStore(DB::connection(), 2);
    $record = new Operation(bin2hex(random_bytes(32)), hash('sha256', 'binding'), OperationState::Prepared, 1, now()->addHour()->getTimestamp());
    $store->create($record);
    $ready = $record->transition(OperationState::Ready);
    $store->transition($record, $ready);
    $executing = $ready->transition(OperationState::Executing);
    $store->transition($ready, $executing);
    if ($driver === 'file') {
        file_put_contents($this->directory . '/.count', '2');
    } else {
        DB::table('mcp_operation_capacity')->where('id', 1)->update(['record_count' => 2]);
    }
    $next = new Operation(bin2hex(random_bytes(32)), hash('sha256', 'binding'), OperationState::Prepared, 1, now()->addHour()->getTimestamp());
    expect(fn () => $store->create($next))->toThrow(RuntimeException::class);
    expect($store->reconcileCapacity())->toBe(1);
    expect($store->create($next))->toBeTrue();
    expect($store->find($record->id)->state)->toBe(OperationState::Executing);
})->with(['file', 'database']);

it('keeps orphan recovery candidates reserved during capacity repair', function () {
    $store = new FileOperationStore($this->directory, 1);
    $store->find(bin2hex(random_bytes(32)));
    file_put_contents($this->directory . '/' . bin2hex(random_bytes(32)) . '.json.tmp', 'unresolved candidate');
    expect($store->reconcileCapacity())->toBe(1);
    $next = new Operation(bin2hex(random_bytes(32)), hash('sha256', 'binding'), OperationState::Prepared, 1, now()->addHour()->getTimestamp());
    expect(fn () => $store->create($next))->toThrow(RuntimeException::class);
});

it('stores and replays valid deep and unicode results without repeating a write', function (string $driver) {
    $store = $driver === 'file' ? new FileOperationStore($this->directory) : new DatabaseOperationStore(DB::connection());
    $coordinator = new OperationCoordinator($store, app(Encrypter::class));
    $action = new ActionDefinition('fixture/retention', 'edit', 1, 'Edit a draft.', ActionEffect::Edit, ['fixture:edit'],
        ['type' => 'object'], ['type' => 'object'], RetentionHandler::class, RetentionPolicy::class);
    $context = new ExecutionContext('actor', 'grant', 'default', 'editor', 'http', 'prepare', hrtime(true) + 10_000_000_000);
    $result = ['content' => str_repeat('æ/', 200000)];
    for ($i = 0; $i < 40; $i++) {
        $result = ['nested' => $result];
    }
    app(SchemaValidator::class)->validate($result, $action->outputSchema);
    $prepared = $coordinator->prepare($action, [], $context);
    $context = $context->withOperationId($prepared->id);
    $calls = 0;
    $write = function () use (&$calls, $result): array {
        $calls++;

        return $result;
    };
    expect($coordinator->execute($action, [], $context, $write)->data)->toBe($result);
    expect($store->find($prepared->id)->state)->toBe(OperationState::Succeeded);
    $replay = $coordinator->execute($action, [], $context, $write);
    expect($replay->replayed)->toBeTrue()->and($replay->data)->toBe($result)->and($calls)->toBe(1);
})->with(['file', 'database']);

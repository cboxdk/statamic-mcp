<?php

declare(strict_types=1);

use Cboxdk\StatamicMcp\Actions\ActionEffect;
use Cboxdk\StatamicMcp\Actions\Operations\IdempotentWrite;
use Cboxdk\StatamicMcp\Actions\Operations\OperationCoordinator;
use Cboxdk\StatamicMcp\Actions\Operations\OperationIntent;
use Cboxdk\StatamicMcp\Approvals\Approvals;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\InputStream;
use Symfony\Component\Process\Process;

/*
 * V05 exit criterion: one approval is claimed once. Real processes race on an
 * approved operation in each store; a crash after the claim leaves it spent.
 */

beforeEach(function () {
    $this->acDir = sys_get_temp_dir() . '/mcp-approval-race-' . bin2hex(random_bytes(8));
    File::makeDirectory($this->acDir, 0700, true);
    $this->acEffects = $this->acDir . '/effects.log';
    touch($this->acEffects);
});

afterEach(function () {
    File::deleteDirectory($this->acDir);
    if (isset($this->acMigration)) {
        $this->acMigration->down();
    }
});

/** An approved delete in the given store, made the way the router makes it. */
function acApproved(string $driver, string $dir): array
{
    $path = $driver === 'file' ? $dir . '/operations' : $dir . '/operations.sqlite';
    if ($driver === 'mysql') {
        test()->acMigration = mcpMysqlOperationTables();
        $path = '-';
    } elseif ($driver === 'database') {
        $pdo = new PDO('sqlite:' . $path);
        $pdo->exec('CREATE TABLE mcp_operations (id VARCHAR(64) PRIMARY KEY, version INTEGER, payload TEXT, state VARCHAR(32), expires_at INTEGER)');
        $pdo->exec('CREATE INDEX mcp_operations_expires_at ON mcp_operations (expires_at)');
        $pdo->exec('CREATE TABLE mcp_operation_capacity (id INTEGER PRIMARY KEY, record_count INTEGER)');
        $pdo->exec('INSERT INTO mcp_operation_capacity VALUES (1, 0)');
    }
    require_once __DIR__ . '/../../Support/operation-store.php';
    $store = mcpWorkerOperationStore($driver, $path);
    $encrypter = new Encrypter(str_repeat('k', 32), 'aes-256-cbc');
    $approvals = new Approvals($store, new OperationCoordinator($store, $encrypter), new IdempotentWrite($store, $encrypter), $encrypter);
    $intent = OperationIntent::forToolCall(bin2hex(random_bytes(32)), 'user:1', 'token:1', 'entries', 'delete', ActionEffect::Delete,
        'default', 'editor', 'http', ['action' => 'delete', 'collection' => 'pages', 'id' => 'one']);
    $operation = $approvals->request($intent, ['description' => 'Delete entries one']);
    $approvals->approve($operation->id, 'user:1');

    return [$path, $operation->id];
}

/** @return list<string> */
function acRun(string $driver, string $path, string $effects, string $id, array $modes): array
{
    $workers = [];
    $inputs = [];
    foreach ($modes as $mode) {
        $input = new InputStream;
        $worker = new Process([PHP_BINARY, __DIR__ . '/../../Support/approval-execute.php', $driver, $path, $effects, $id, $mode]);
        $worker->setInput($input)->setTimeout(20)->start();
        $workers[] = $worker;
        $inputs[] = $input;
    }
    foreach ($workers as $worker) {
        expect(waitForWorkerReady($worker))->toBeTrue($worker->getErrorOutput());
    }
    foreach ($inputs as $input) {
        $input->write("go\n");
        $input->close();
    }
    foreach ($workers as $worker) {
        $worker->wait();
    }

    return array_map(fn (Process $worker): string => trim(str_replace('ready', '', $worker->getOutput())), $workers);
}

function acWrites(string $effects): int
{
    return count(array_filter(explode("\n", (string) file_get_contents($effects))));
}

it('runs an approved call once when several processes claim it', function (string $driver) {
    [$path, $id] = acApproved($driver, $this->acDir);

    $statuses = acRun($driver, $path, $this->acEffects, $id, ['write', 'write', 'write', 'write']);

    expect(acWrites($this->acEffects))->toBe(1)
        ->and(array_count_values($statuses)['executed'] ?? 0)->toBe(1, implode(',', $statuses));
    foreach ($statuses as $status) {
        expect($status)->toBeIn(['executed', 'unknown', 'replayed']);
    }
    expect(acRun($driver, $path, $this->acEffects, $id, ['write']))->toBe(['replayed'])
        ->and(acWrites($this->acEffects))->toBe(1);
})->with(['file', 'database', 'mysql']);

it('keeps an approval spent after a crash mid-write', function (string $driver) {
    [$path, $id] = acApproved($driver, $this->acDir);

    acRun($driver, $path, $this->acEffects, $id, ['crash']);

    expect(acRun($driver, $path, $this->acEffects, $id, ['write', 'write']))->toBe(['unknown', 'unknown'])
        ->and(acWrites($this->acEffects))->toBe(1);
})->with(['file', 'database', 'mysql']);

<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\InputStream;
use Symfony\Component\Process\Process;

/*
 * V04 exit criterion: concurrent and crashing callers with one idempotency key,
 * in real processes, against the file store and the database store on SQLite
 * and MySQL/PostgreSQL. The write must land once,
 * and a retry after a crash must report an unknown outcome, never write again.
 */

beforeEach(function () {
    $this->iwDir = sys_get_temp_dir() . '/mcp-idempotent-' . bin2hex(random_bytes(8));
    File::makeDirectory($this->iwDir, 0700, true);
    $this->iwEffects = $this->iwDir . '/effects.log';
    touch($this->iwEffects);
});

afterEach(function () {
    File::deleteDirectory($this->iwDir);
    if (isset($this->iwMigration)) {
        $this->iwMigration->down();
    }
});

function iwStorePath(string $driver, string $dir): string
{
    if ($driver === 'file') {
        return $dir . '/operations';
    }
    if (in_array($driver, ['mysql', 'pgsql'], true)) {
        test()->iwMigration = mcpServerOperationTables($driver);

        return '-';
    }
    $path = $dir . '/operations.sqlite';
    touch($path);
    config(['database.connections.iw_ops' => ['driver' => 'sqlite', 'database' => $path, 'prefix' => '']]);
    $schema = DB::connection('iw_ops')->getSchemaBuilder();
    $schema->create('mcp_operations', function (Blueprint $table): void {
        $table->string('id', 64)->primary();
        $table->unsignedBigInteger('version');
        $table->longText('payload');
        $table->string('state', 32);
        $table->unsignedBigInteger('expires_at')->index();
    });
    $schema->create('mcp_operation_capacity', function (Blueprint $table): void {
        $table->unsignedInteger('id')->primary();
        $table->unsignedInteger('record_count');
    });
    DB::connection('iw_ops')->table('mcp_operation_capacity')->insert(['id' => 1, 'record_count' => 0]);
    DB::purge('iw_ops');

    return $path;
}

/** @return list<Process> */
function iwRun(string $driver, string $path, string $effects, array $modes): array
{
    $script = __DIR__ . '/../../Support/idempotent-write.php';
    $workers = [];
    $inputs = [];
    foreach ($modes as $mode) {
        $input = new InputStream;
        $worker = new Process([PHP_BINARY, $script, $driver, $path, $effects, $mode]);
        $worker->setInput($input);
        $worker->setTimeout(20);
        $worker->start();
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

    return $workers;
}

function iwStatus(Process $worker): string
{
    return trim(str_replace('ready', '', $worker->getOutput()));
}

function iwWrites(string $effects): int
{
    return count(array_filter(explode("\n", (string) file_get_contents($effects))));
}

it('writes once when several processes race with the same key', function (string $driver) {
    $path = iwStorePath($driver, $this->iwDir);

    $statuses = array_map('iwStatus', iwRun($driver, $path, $this->iwEffects, ['write', 'write', 'write', 'write']));

    expect(iwWrites($this->iwEffects))->toBe(1)
        ->and(array_count_values($statuses)['executed'] ?? 0)->toBe(1, implode(',', $statuses));
    foreach ($statuses as $status) {
        expect($status)->toBeIn(['executed', 'unknown', 'replayed']);
    }

    // Once it finished, a retry replays the result instead of writing.
    expect(iwStatus(iwRun($driver, $path, $this->iwEffects, ['write'])[0]))->toBe('replayed')
        ->and(iwWrites($this->iwEffects))->toBe(1);
})->with(['file', 'database', 'mysql', 'pgsql']);

it('answers unknown and never writes again after a crash mid-write', function (string $driver) {
    $path = iwStorePath($driver, $this->iwDir);

    $crashed = iwRun($driver, $path, $this->iwEffects, ['crash'])[0];
    expect($crashed->getExitCode())->toBe(9)
        ->and(iwWrites($this->iwEffects))->toBe(1);

    $retries = array_map('iwStatus', iwRun($driver, $path, $this->iwEffects, ['write', 'write']));

    expect($retries)->toBe(['unknown', 'unknown'])
        ->and(iwWrites($this->iwEffects))->toBe(1);
})->with(['file', 'database', 'mysql', 'pgsql']);

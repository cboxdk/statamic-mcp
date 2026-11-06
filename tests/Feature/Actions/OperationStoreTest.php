<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Tests\Feature\Actions;

use Cboxdk\StatamicMcp\Actions\Contracts\OperationStore;
use Cboxdk\StatamicMcp\Actions\Operations\DatabaseOperationStore;
use Cboxdk\StatamicMcp\Actions\Operations\FileOperationStore;
use Cboxdk\StatamicMcp\Actions\Operations\Operation;
use Cboxdk\StatamicMcp\Actions\Operations\OperationState;
use Cboxdk\StatamicMcp\Tests\TestCase;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\InputStream;
use Symfony\Component\Process\Process;

class OperationStoreTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = sys_get_temp_dir() . '/mcp-operations-' . bin2hex(random_bytes(8));
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->directory);
        parent::tearDown();
    }

    public function test_file_store_rejects_replay_stale_workers_and_binding_changes(): void
    {
        $this->exercise(new FileOperationStore($this->directory));
    }

    public function test_database_store_rejects_replay_stale_workers_and_binding_changes(): void
    {
        config(['database.connections.operation_test' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']]);
        $connection = DB::connection('operation_test');
        $connection->getSchemaBuilder()->create('mcp_operations', function (Blueprint $table): void {
            $table->string('id', 64)->primary();
            $table->unsignedBigInteger('version');
            $table->text('payload')->collation('NOCASE');
        });
        $this->exercise(new DatabaseOperationStore($connection));

        // Case-insensitive SQL equality must never authorize a stale/forged record.
        $record = new Operation(hash('sha256', 'collation'), hash('sha256', 'binding'), OperationState::Prepared, 1, time() + 300);
        $store = new DatabaseOperationStore($connection);
        $store->create($record);
        $payload = json_encode($record->toArray(), JSON_THROW_ON_ERROR);
        $connection->table('mcp_operations')->where('id', $record->id)->update(['payload' => str_replace('prepared', 'PREPARED', $payload)]);
        $this->assertFalse($store->transition($record, $record->transition(OperationState::Ready)));
    }

    public function test_operation_migration_supports_store_and_rollback(): void
    {
        $migration = require __DIR__ . '/../../../database/migrations/operations/0001_create_mcp_operations_table.php';
        $migration->up();
        try {
            $this->exercise(new DatabaseOperationStore(DB::connection()));
        } finally {
            $migration->down();
        }
        $this->assertFalse(DB::connection()->getSchemaBuilder()->hasTable('mcp_operations'));
    }

    private function exercise(OperationStore $store): void
    {
        $operation = new Operation(hash('sha256', 'operation'), hash('sha256', 'binding'), OperationState::Prepared, 1, time() + 300);
        $this->assertTrue($store->create($operation));
        $this->assertFalse($store->create($operation));
        $ready = $operation->transition(OperationState::Ready);
        $this->assertTrue($store->transition($operation, $ready));
        $this->assertFalse($store->transition($operation, $ready));
        $executing = $ready->transition(OperationState::Executing);
        $this->assertTrue($store->transition($ready, $executing));
        $this->assertFalse($store->transition($ready, $executing));
        $unknown = $executing->transition(OperationState::OutcomeUnknown);
        $this->assertTrue($store->transition($executing, $unknown));
        $this->assertSame(OperationState::OutcomeUnknown, $store->find($operation->id)->state);
        try {
            $unknown->transition(OperationState::Ready);
            $this->fail('An unknown outcome must not become executable again.');
        } catch (\LogicException $error) {
            $this->assertSame('Invalid operation transition.', $error->getMessage());
        }
    }

    public function test_expired_operation_cannot_be_claimed(): void
    {
        $operation = new Operation(hash('sha256', 'operation'), hash('sha256', 'binding'), OperationState::Ready, 2, time() - 1);
        $this->expectException(\LogicException::class);
        $operation->transition(OperationState::Executing);
    }

    public function test_record_cannot_change_identity_during_transition(): void
    {
        $store = new FileOperationStore($this->directory);
        $operation = new Operation(hash('sha256', 'operation'), hash('sha256', 'binding'), OperationState::Prepared, 1, time() + 300);
        $store->create($operation);
        $forged = new Operation($operation->id, hash('sha256', 'different actor'), OperationState::Ready, 2, $operation->expiresAt);
        $this->expectException(\InvalidArgumentException::class);
        $store->transition($operation, $forged);
    }

    public function test_file_store_refuses_path_traversal(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new FileOperationStore($this->directory))->find('../outside');
    }

    public function test_database_claim_is_atomic_across_processes_and_survives_crash(): void
    {
        File::makeDirectory($this->directory, 0700, true);
        $path = $this->directory . '/operations.sqlite';
        touch($path);
        config(['database.connections.operation_concurrent' => ['driver' => 'sqlite', 'database' => $path, 'prefix' => '']]);
        $connection = DB::connection('operation_concurrent');
        $connection->getSchemaBuilder()->create('mcp_operations', function (Blueprint $table): void {
            $table->string('id', 64)->primary();
            $table->unsignedBigInteger('version');
            $table->longText('payload');
        });
        $store = new DatabaseOperationStore($connection);
        $operation = new Operation(hash('sha256', 'concurrent'), hash('sha256', 'binding'), OperationState::Prepared, 1, time() + 300);
        $store->create($operation);
        $store->transition($operation, $operation->transition(OperationState::Ready));
        $command = [PHP_BINARY, __DIR__ . '/../../Support/database-operation-claim.php', $path, $operation->id];
        $inputs = [new InputStream, new InputStream];
        $workers = [new Process($command), new Process($command)];
        try {
            foreach ($workers as $index => $worker) {
                $worker->setInput($inputs[$index]);
                $worker->setTimeout(10);
                $worker->start();
            }
            foreach ($workers as $worker) {
                $this->assertTrue($worker->waitUntil(fn (): bool => str_contains($worker->getOutput(), "ready\n")));
            }
            foreach ($inputs as $input) {
                $input->write("claim\n");
                $input->close();
            }
            foreach ($workers as $worker) {
                $worker->wait();
            }
            $outputs = array_map(fn (Process $worker): string => $worker->getOutput(), $workers);
            sort($outputs);
            $this->assertSame(["ready\nclaimed", "ready\nlost"], $outputs);
            $this->assertContains(9, array_map(fn (Process $worker): ?int => $worker->getExitCode(), $workers));
            $this->assertSame(OperationState::Executing, $store->find($operation->id)->state);
            $retry = new Process($command);
            $retry->mustRun();
            $this->assertSame('lost', $retry->getOutput());
        } finally {
            foreach ($workers as $worker) {
                $worker->stop();
            }
            DB::purge('operation_concurrent');
        }
    }

    public function test_only_one_process_can_claim_and_a_crashed_claim_is_not_released(): void
    {
        $store = new FileOperationStore($this->directory);
        $operation = new Operation(hash('sha256', 'concurrent'), hash('sha256', 'binding'), OperationState::Prepared, 1, time() + 300);
        $store->create($operation);
        $store->transition($operation, $operation->transition(OperationState::Ready));
        $command = [PHP_BINARY, __DIR__ . '/../../Support/operation-claim.php', $this->directory, $operation->id, 'crash'];
        $one = new Process($command);
        $two = new Process($command);
        $one->start();
        $two->start();
        $one->wait();
        $two->wait();
        $outputs = [$one->getOutput(), $two->getOutput()];
        sort($outputs);
        $this->assertSame(['claimed', 'lost'], $outputs);
        $this->assertContains(9, [$one->getExitCode(), $two->getExitCode()]);
        $this->assertSame(OperationState::Executing, $store->find($operation->id)->state);
        $retry = new Process($command);
        $retry->run();
        $this->assertSame('lost', $retry->getOutput());
    }
}

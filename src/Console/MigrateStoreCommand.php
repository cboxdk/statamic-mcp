<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Console;

use Cboxdk\StatamicMcp\Contracts\AuditStore;
use Cboxdk\StatamicMcp\Contracts\TokenStore;
use Cboxdk\StatamicMcp\Storage\Audit\DatabaseAuditStore;
use Cboxdk\StatamicMcp\Storage\Audit\FileAuditStore;
use Cboxdk\StatamicMcp\Storage\Tokens\DatabaseTokenStore;
use Cboxdk\StatamicMcp\Storage\Tokens\FileTokenStore;
use Illuminate\Console\Command;

class MigrateStoreCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'mcp:migrate-store
        {store : Store to migrate (tokens or audit)}
        {--from= : Source driver (file or database)}
        {--to= : Target driver (file or database)}';

    /**
     * @var string
     */
    protected $description = 'Migrate MCP data between storage drivers';

    public function handle(): int
    {
        $store = $this->argument('store');
        $from = $this->option('from');
        $to = $this->option('to');

        if (! is_string($store) || ! in_array($store, ['tokens', 'audit'], true)) {
            $this->error('Store must be "tokens" or "audit".');

            return self::FAILURE;
        }

        if (! is_string($from) || ! in_array($from, ['file', 'database'], true)) {
            $this->error('The --from option must be "file" or "database".');

            return self::FAILURE;
        }

        if (! is_string($to) || ! in_array($to, ['file', 'database'], true)) {
            $this->error('The --to option must be "file" or "database".');

            return self::FAILURE;
        }

        if ($from === $to) {
            $this->error('The --from and --to drivers must be different.');

            return self::FAILURE;
        }

        return $store === 'tokens'
            ? $this->migrateTokens($from, $to)
            : $this->migrateAudit($from, $to);
    }

    private function migrateTokens(string $from, string $to): int
    {
        $source = $this->resolveTokenStore($from);
        $target = $this->resolveTokenStore($to);

        $tokens = $source->listAll();
        $count = $tokens->count();

        if ($count === 0) {
            $this->info('No tokens found in source store. Nothing to migrate.');

            return self::SUCCESS;
        }

        if (! $this->confirm("Found {$count} token(s) in {$from} store. Migrate to {$to}?")) {
            $this->info('Migration cancelled.');

            return self::SUCCESS;
        }

        $bar = $this->output->createProgressBar($count);
        $bar->start();

        $migrated = 0;

        foreach ($tokens as $token) {
            $target->import($token);
            $migrated++;
            $bar->advance();
        }

        $bar->finish();
        $this->newLine();
        $this->info("Migrated {$migrated} token(s). Source data preserved — verify and clean up manually.");

        return self::SUCCESS;
    }

    private function migrateAudit(string $from, string $to): int
    {
        $source = $this->resolveAuditStore($from);
        $target = $this->resolveAuditStore($to);

        $result = $source->query(null, null, 1, PHP_INT_MAX);
        $count = $result->total;

        if ($count === 0) {
            $this->info('No audit entries found in source store. Nothing to migrate.');

            return self::SUCCESS;
        }

        if (! $this->confirm("Found {$count} audit entry/entries in {$from} store. Migrate to {$to}?")) {
            $this->info('Migration cancelled.');

            return self::SUCCESS;
        }

        $bar = $this->output->createProgressBar($count);
        $bar->start();

        $migrated = 0;

        foreach ($result->entries as $entry) {
            /** @var array{level: string, message: string, tool?: string, action?: string, status?: string, correlation_id?: string, duration_ms?: float, timestamp: string, metadata?: array<string, mixed>} $entry */
            $target->write($entry);
            $migrated++;
            $bar->advance();
        }

        $bar->finish();
        $this->newLine();
        $this->info("Migrated {$migrated} audit entry/entries. Source data preserved — verify and clean up manually.");

        return self::SUCCESS;
    }

    private function resolveTokenStore(string $driver): TokenStore
    {
        if ($driver === 'file') {
            /** @var string $path */
            $path = config('statamic.mcp.storage.tokens_path', storage_path('statamic-mcp/tokens'));

            return new FileTokenStore($path);
        }

        /** @var TokenStore $store */
        $store = app()->make(DatabaseTokenStore::class);

        return $store;
    }

    private function resolveAuditStore(string $driver): AuditStore
    {
        if ($driver === 'file') {
            /** @var string $path */
            $path = config('statamic.mcp.storage.audit_path', storage_path('statamic-mcp/audit.log'));

            return new FileAuditStore($path);
        }

        /** @var AuditStore $store */
        $store = app()->make(DatabaseAuditStore::class);

        return $store;
    }
}

<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Tests\Stress;

use Carbon\Carbon;
use Cboxdk\StatamicMcp\Storage\Audit\DatabaseAuditStore;
use Cboxdk\StatamicMcp\Storage\Audit\FileAuditStore;
use Cboxdk\StatamicMcp\Storage\Tokens\DatabaseTokenStore;
use Cboxdk\StatamicMcp\Storage\Tokens\FileTokenStore;
use Cboxdk\StatamicMcp\Tests\TestCase;

class MigrationRoundtripTest extends TestCase
{
    private string $tempDir;

    private string $tempAuditPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tempDir = sys_get_temp_dir() . '/statamic-mcp-roundtrip-token-' . uniqid();
        mkdir($this->tempDir, 0755, true);

        $this->tempAuditPath = sys_get_temp_dir() . '/statamic-mcp-roundtrip-audit-' . uniqid() . '.log';

        // Run token migrations in correct order
        $create = include __DIR__ . '/../../database/migrations/tokens/create_mcp_tokens_table.php';
        $create->up();

        $addIndex = include __DIR__ . '/../../database/migrations/tokens/add_unique_token_index_to_mcp_tokens_table.php';
        $addIndex->up();

        // Run audit migrations
        $audit = include __DIR__ . '/../../database/migrations/audit/create_mcp_audit_logs_table.php';
        $audit->up();
    }

    protected function tearDown(): void
    {
        $this->deleteDirectory($this->tempDir);

        if (file_exists($this->tempAuditPath)) {
            unlink($this->tempAuditPath);
        }

        parent::tearDown();
    }

    public function test_token_file_to_database_and_back(): void
    {
        $fileStore = new FileTokenStore($this->tempDir);
        $dbStore = new DatabaseTokenStore;

        // Create 100 tokens in file store
        $originalTokens = [];
        for ($i = 0; $i < 100; $i++) {
            $token = $fileStore->create(
                'user-roundtrip',
                "Token {$i}",
                'roundtrip_hash_' . $i,
                ['content:read', 'content:write'],
                $i % 2 === 0 ? Carbon::now()->addDay() : null,
            );
            $originalTokens[$token->id] = $token;
        }

        // Import all file tokens into database
        foreach ($originalTokens as $token) {
            $dbStore->import($token);
        }

        // Verify all 100 exist in database with identical fields
        $dbTokens = $dbStore->listAll();
        $this->assertSame(100, $dbTokens->count());

        foreach ($dbTokens as $dbToken) {
            $original = $originalTokens[$dbToken->id] ?? null;
            $this->assertNotNull($original, "Token {$dbToken->id} should exist in originals");
            $this->assertSame($original->userId, $dbToken->userId);
            $this->assertSame($original->name, $dbToken->name);
            $this->assertSame($original->tokenHash, $dbToken->tokenHash);
            $this->assertSame($original->scopes, $dbToken->scopes);
        }

        // Migrate back: database to a fresh file store
        $returnDir = sys_get_temp_dir() . '/statamic-mcp-roundtrip-return-' . uniqid();
        mkdir($returnDir, 0755, true);
        $returnFileStore = new FileTokenStore($returnDir);

        foreach ($dbTokens as $dbToken) {
            $returnFileStore->import($dbToken);
        }

        // Verify all 100 in file store
        $returnTokens = $returnFileStore->listAll();
        $this->assertSame(100, $returnTokens->count());

        foreach ($returnTokens as $returnToken) {
            $original = $originalTokens[$returnToken->id] ?? null;
            $this->assertNotNull($original);
            $this->assertSame($original->userId, $returnToken->userId);
            $this->assertSame($original->name, $returnToken->name);
            $this->assertSame($original->tokenHash, $returnToken->tokenHash);
            $this->assertSame($original->scopes, $returnToken->scopes);
        }

        $this->deleteDirectory($returnDir);
    }

    public function test_audit_file_to_database_and_back(): void
    {
        $fileStore = new FileAuditStore($this->tempAuditPath);
        $dbStore = new DatabaseAuditStore;

        // Write 100 entries to file store
        for ($i = 0; $i < 100; $i++) {
            $fileStore->write([
                'level' => 'info',
                'message' => "Roundtrip entry {$i}",
                'tool' => 'statamic-entries',
                'action' => 'list',
                'status' => 'success',
                'timestamp' => Carbon::now()->subMinutes(100 - $i)->toIso8601String(),
            ]);
        }

        // Read all from file
        $fileResult = $fileStore->query(null, null, 1, PHP_INT_MAX);
        $this->assertSame(100, $fileResult->total);

        // Write each entry to database
        foreach ($fileResult->entries as $entry) {
            $dbStore->write($entry);
        }

        // Read from database and verify
        $dbResult = $dbStore->query(null, null, 1, PHP_INT_MAX);
        $this->assertSame(100, $dbResult->total);

        // Verify content matches
        foreach ($dbResult->entries as $dbEntry) {
            $this->assertSame('info', $dbEntry['level']);
            $this->assertSame('statamic-entries', $dbEntry['tool']);
            $this->assertSame('success', $dbEntry['status']);
        }
    }

    private function deleteDirectory(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }

        $items = scandir($dir);
        if ($items === false) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $path = $dir . '/' . $item;

            if (is_dir($path)) {
                $this->deleteDirectory($path);
            } else {
                unlink($path);
            }
        }

        rmdir($dir);
    }
}

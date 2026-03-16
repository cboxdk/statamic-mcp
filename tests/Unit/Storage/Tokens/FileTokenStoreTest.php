<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Tests\Unit\Storage\Tokens;

use Cboxdk\StatamicMcp\Contracts\TokenStore;
use Cboxdk\StatamicMcp\Storage\Tokens\FileTokenStore;
use Cboxdk\StatamicMcp\Tests\Concerns\TokenStoreContractTests;
use PHPUnit\Framework\TestCase;

class FileTokenStoreTest extends TestCase
{
    use TokenStoreContractTests;

    private string $tempDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tempDir = sys_get_temp_dir() . '/statamic-mcp-token-test-' . uniqid();
        mkdir($this->tempDir, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->deleteDirectory($this->tempDir);

        parent::tearDown();
    }

    protected function createStore(): TokenStore
    {
        return new FileTokenStore($this->tempDir);
    }

    public function test_index_rebuilds_after_deletion(): void
    {
        $store = new FileTokenStore($this->tempDir);

        $token = $store->create('user-1', 'Index Test', 'hash_index', ['*'], null);

        // Delete the .index file manually
        $indexPath = $this->tempDir . '/.index';
        if (file_exists($indexPath)) {
            unlink($indexPath);
        }

        // findByHash should still work via index rebuild
        $found = $store->findByHash('hash_index');
        $this->assertNotNull($found);
        $this->assertSame($token->id, $found->id);

        // Verify index was rebuilt
        $this->assertFileExists($indexPath);
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

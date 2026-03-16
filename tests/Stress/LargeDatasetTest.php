<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Tests\Stress;

use Carbon\Carbon;
use Cboxdk\StatamicMcp\Storage\Tokens\FileTokenStore;
use PHPUnit\Framework\TestCase;

class LargeDatasetTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tempDir = sys_get_temp_dir() . '/statamic-mcp-scale-' . uniqid();
        mkdir($this->tempDir, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->deleteDirectory($this->tempDir);

        parent::tearDown();
    }

    public function test_list_all_scaling_is_roughly_linear(): void
    {
        $small = $this->benchmarkListAll(50);
        $large = $this->benchmarkListAll(500);

        // 10x data should take less than 15x time (linear, not quadratic)
        // Floor of 0.01s avoids flakiness when small benchmark is sub-millisecond
        $this->assertLessThan(max($small, 0.01) * 20, $large);
    }

    public function test_search_by_user_scaling_is_roughly_linear(): void
    {
        $small = $this->benchmarkSearchByUser(50);
        $large = $this->benchmarkSearchByUser(500);

        // Floor of 0.01s avoids flakiness when small benchmark is sub-millisecond
        $this->assertLessThan(max($small, 0.01) * 20, $large);
    }

    public function test_prune_scaling_is_roughly_linear(): void
    {
        $small = $this->benchmarkPrune(50);
        $large = $this->benchmarkPrune(500);

        // Floor of 0.01s avoids flakiness when small benchmark is sub-millisecond
        $this->assertLessThan(max($small, 0.01) * 20, $large);
    }

    /**
     * Create N tokens and measure listAll time.
     */
    private function benchmarkListAll(int $count): float
    {
        $dir = $this->tempDir . '/list-' . $count;
        mkdir($dir, 0755, true);
        $store = new FileTokenStore($dir);

        for ($i = 0; $i < $count; $i++) {
            $store->create('user-bench', "Token {$i}", 'bench_list_' . $count . '_' . $i, ['*'], null);
        }

        $start = microtime(true);
        $store->listAll();
        $elapsed = microtime(true) - $start;

        return $elapsed;
    }

    /**
     * Create N tokens and measure listForUser time.
     */
    private function benchmarkSearchByUser(int $count): float
    {
        $dir = $this->tempDir . '/search-' . $count;
        mkdir($dir, 0755, true);
        $store = new FileTokenStore($dir);

        for ($i = 0; $i < $count; $i++) {
            $userId = $i % 2 === 0 ? 'user-target' : 'user-other';
            $store->create($userId, "Token {$i}", 'bench_search_' . $count . '_' . $i, ['*'], null);
        }

        $start = microtime(true);
        $store->listForUser('user-target');
        $elapsed = microtime(true) - $start;

        return $elapsed;
    }

    /**
     * Create N expired tokens and measure prune time.
     */
    private function benchmarkPrune(int $count): float
    {
        $dir = $this->tempDir . '/prune-' . $count;
        mkdir($dir, 0755, true);
        $store = new FileTokenStore($dir);

        for ($i = 0; $i < $count; $i++) {
            $store->create(
                'user-bench',
                "Token {$i}",
                'bench_prune_' . $count . '_' . $i,
                ['*'],
                Carbon::now()->subHour(),
            );
        }

        $start = microtime(true);
        $store->pruneExpired();
        $elapsed = microtime(true) - $start;

        return $elapsed;
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

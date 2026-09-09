<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Tests\Stress;

use Carbon\Carbon;
use Cboxdk\StatamicMcp\Storage\Tokens\FileTokenStore;
use PHPUnit\Framework\TestCase;

class LargeDatasetTest extends TestCase
{
    /**
     * Runs per measurement. The fastest is kept: a benchmark can only be made
     * slower by interference (a busy CI runner, a competing process), never
     * faster, so the minimum is the closest estimate of the real cost and is
     * far steadier than a single sample. Timing these on shared runners is
     * what made this file flaky before.
     */
    private const REPETITIONS = 3;

    /** 10x the data, so anything close to linear lands near 10x the time. */
    private const TOLERANCE = 20;

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
        $small = $this->fastestOf('benchmarkListAll', 50);
        $large = $this->fastestOf('benchmarkListAll', 500);

        // Floor of 0.01s avoids a false failure when the small run is
        // sub-millisecond and the ratio becomes meaningless.
        $this->assertLessThan(max($small, 0.01) * self::TOLERANCE, $large);
    }

    public function test_search_by_user_scaling_is_roughly_linear(): void
    {
        $small = $this->fastestOf('benchmarkSearchByUser', 50);
        $large = $this->fastestOf('benchmarkSearchByUser', 500);

        // Floor of 0.01s avoids a false failure when the small run is
        // sub-millisecond and the ratio becomes meaningless.
        $this->assertLessThan(max($small, 0.01) * self::TOLERANCE, $large);
    }

    public function test_prune_scaling_is_roughly_linear(): void
    {
        $small = $this->fastestOf('benchmarkPrune', 50);
        $large = $this->fastestOf('benchmarkPrune', 500);

        // Floor of 0.01s avoids a false failure when the small run is
        // sub-millisecond and the ratio becomes meaningless.
        $this->assertLessThan(max($small, 0.01) * self::TOLERANCE, $large);
    }

    /**
     * Fastest of REPETITIONS runs of one benchmark, each on its own directory.
     *
     * @param  'benchmarkListAll'|'benchmarkSearchByUser'|'benchmarkPrune'  $benchmark
     */
    private function fastestOf(string $benchmark, int $count): float
    {
        $best = null;

        for ($run = 0; $run < self::REPETITIONS; $run++) {
            $elapsed = $this->{$benchmark}($count, $run);
            $best = $best === null ? $elapsed : min($best, $elapsed);
        }

        return $best ?? 0.0;
    }

    /**
     * Create N tokens and measure listAll time.
     */
    private function benchmarkListAll(int $count, int $run = 0): float
    {
        $dir = $this->tempDir . '/list-' . $count . '-' . $run;
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
    private function benchmarkSearchByUser(int $count, int $run = 0): float
    {
        $dir = $this->tempDir . '/search-' . $count . '-' . $run;
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
    private function benchmarkPrune(int $count, int $run = 0): float
    {
        $dir = $this->tempDir . '/prune-' . $count . '-' . $run;
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

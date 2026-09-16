<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Tests\Stress;

use Carbon\Carbon;
use Cboxdk\StatamicMcp\Storage\Tokens\FileTokenStore;
use Cboxdk\StatamicMcp\Tests\Support\CountingStreamWrapper;
use PHPUnit\Framework\TestCase;

/**
 * Guards the token store against algorithmic regressions — the kind that make
 * an operation quadratic in the number of tokens rather than linear.
 *
 * Measured in **CPU time**, not wall-clock. Wall-clock says nothing about an
 * algorithm on a machine running anything else: on a loaded box these same
 * operations were observed swinging from 6ms to 710ms for identical work, and
 * a prune of 50 tokens timed *slower* than a prune of 500. CPU time is what
 * the process actually consumed, so a competing process cannot inflate it —
 * across repeated runs under load average 112 it stayed within ~5%, while
 * wall-clock varied by 100x.
 *
 * User and system time are both counted: the regression this file exists to
 * catch (an index rewritten once per removed token instead of once per batch)
 * burns both, in json encode/decode and in the write syscalls.
 */
class LargeDatasetTest extends TestCase
{
    /**
     * Runs per measurement. The lowest is kept: a benchmark can be made more
     * expensive by interference but never cheaper, so the minimum is the
     * closest estimate of the real cost.
     */
    private const REPETITIONS = 3;

    /** 10x the data, so anything close to linear lands near 10x the cost. */
    private const TOLERANCE = 20;

    /**
     * Guards against a degenerate zero reading only.
     *
     * Deliberately far below the ~5ms the small runs actually consume, so it
     * never becomes the effective budget. The old wall-clock floor was 0.01s,
     * which the sub-millisecond small runs always tripped — turning a scaling
     * comparison into "the large run must finish within 200ms", an absolute
     * performance assertion that a busy machine failed at random.
     */
    private const FLOOR = 0.001;

    private string $tempDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tempDir = sys_get_temp_dir() . '/statamic-mcp-scale-' . uniqid();
        mkdir($this->tempDir, 0755, true);
    }

    protected function tearDown(): void
    {
        CountingStreamWrapper::unregister();
        CountingStreamWrapper::reset();

        $this->deleteDirectory($this->tempDir);

        parent::tearDown();
    }

    /**
     * A store whose filesystem calls are counted.
     */
    private function countedStore(string $name): FileTokenStore
    {
        $path = $this->tempDir . '/' . $name;
        mkdir($path, 0755, true);

        CountingStreamWrapper::register();
        CountingStreamWrapper::reset();

        return new FileTokenStore(CountingStreamWrapper::SCHEME . '://' . $path);
    }

    /**
     * The invariant the quadratic prune bug actually violated.
     *
     * Counted, not timed. A clock cannot see this: scanning and unlinking N
     * token files is linear work in both implementations and dwarfs the index
     * writes, so reintroducing the bug moved the measured CPU ratio only from
     * 1.35x to 2.12x — indistinguishable from noise. Counted, the same
     * reintroduction moves this from 1 to 50.
     */
    public function test_pruning_rewrites_the_index_once_not_once_per_token(): void
    {
        $store = $this->countedStore('prune-writes');

        for ($i = 0; $i < 50; $i++) {
            $store->create('user-bench', "Token {$i}", 'prune_w_' . $i, ['*'], Carbon::now()->subHour());
        }

        CountingStreamWrapper::reset();
        $pruned = $store->pruneExpired();

        $this->assertSame(50, $pruned, 'Expected every expired token to be pruned.');
        $this->assertSame(1, CountingStreamWrapper::writesTo('/.index'), 'Pruning a batch must rewrite the index once. One write per removed token is the quadratic regression this guards.');
    }

    /**
     * Same invariant on the other method that had it. See above.
     */
    public function test_deleting_a_users_tokens_rewrites_the_index_once(): void
    {
        $store = $this->countedStore('delete-writes');

        for ($i = 0; $i < 50; $i++) {
            $store->create('user-target', "Token {$i}", 'del_w_' . $i, ['*'], null);
        }

        CountingStreamWrapper::reset();
        $deleted = $store->deleteForUser('user-target');

        $this->assertSame(50, $deleted, 'Expected every token for the user to be deleted.');
        $this->assertSame(1, CountingStreamWrapper::writesTo('/.index'), 'Deleting a user\'s tokens must rewrite the index once.');
    }

    public function test_list_all_scaling_is_roughly_linear(): void
    {
        $small = $this->lowestOf('benchmarkListAll', 50);
        $large = $this->lowestOf('benchmarkListAll', 500);

        $this->assertScalesLinearly($small, $large);
    }

    public function test_search_by_user_scaling_is_roughly_linear(): void
    {
        $small = $this->lowestOf('benchmarkSearchByUser', 50);
        $large = $this->lowestOf('benchmarkSearchByUser', 500);

        $this->assertScalesLinearly($small, $large);
    }

    public function test_prune_scaling_is_roughly_linear(): void
    {
        $small = $this->lowestOf('benchmarkPrune', 50);
        $large = $this->lowestOf('benchmarkPrune', 500);

        $this->assertScalesLinearly($small, $large);
    }

    /**
     * Assert that a 10x larger dataset did not cost disproportionately more.
     *
     * A linear implementation lands near 10x and in practice below it, since
     * fixed per-call overhead is amortised. A quadratic one lands near 100x,
     * so the 20x tolerance separates the two with room to spare rather than
     * measuring how fast the machine is.
     */
    private function assertScalesLinearly(float $small, float $large): void
    {
        $budget = max($small, self::FLOOR) * self::TOLERANCE;

        $this->assertLessThan($budget, $large, sprintf(
            'Cost grew %.1fx for 10x the data (%.5fs CPU -> %.5fs CPU); anything near %dx suggests the operation went quadratic.',
            $small > 0 ? $large / $small : INF,
            $small,
            $large,
            self::TOLERANCE,
        ));
    }

    /**
     * Lowest of REPETITIONS runs of one benchmark, each on its own directory.
     *
     * @param  'benchmarkListAll'|'benchmarkSearchByUser'|'benchmarkPrune'  $benchmark
     */
    private function lowestOf(string $benchmark, int $count): float
    {
        $best = null;

        for ($run = 0; $run < self::REPETITIONS; $run++) {
            $cost = $this->{$benchmark}($count, $run);
            $best = $best === null ? $cost : min($best, $cost);
        }

        return $best ?? 0.0;
    }

    /**
     * CPU seconds this process has consumed, user and system combined.
     */
    private function cpuSeconds(): float
    {
        $usage = getrusage();

        if (! is_array($usage)) {
            $this->markTestSkipped('getrusage() is unavailable, so CPU time cannot be measured.');
        }

        return ($usage['ru_utime.tv_sec'] ?? 0) + ($usage['ru_utime.tv_usec'] ?? 0) / 1e6
            + ($usage['ru_stime.tv_sec'] ?? 0) + ($usage['ru_stime.tv_usec'] ?? 0) / 1e6;
    }

    /**
     * Create N tokens and measure the CPU cost of listAll.
     */
    private function benchmarkListAll(int $count, int $run = 0): float
    {
        $dir = $this->tempDir . '/list-' . $count . '-' . $run;
        mkdir($dir, 0755, true);
        $store = new FileTokenStore($dir);

        for ($i = 0; $i < $count; $i++) {
            $store->create('user-bench', "Token {$i}", 'bench_list_' . $count . '_' . $i, ['*'], null);
        }

        $start = $this->cpuSeconds();
        $store->listAll();

        return $this->cpuSeconds() - $start;
    }

    /**
     * Create N tokens and measure the CPU cost of listForUser.
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

        $start = $this->cpuSeconds();
        $store->listForUser('user-target');

        return $this->cpuSeconds() - $start;
    }

    /**
     * Create N expired tokens and measure the CPU cost of pruneExpired.
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

        $start = $this->cpuSeconds();
        $store->pruneExpired();

        return $this->cpuSeconds() - $start;
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

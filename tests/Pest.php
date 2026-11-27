<?php

use Cboxdk\StatamicMcp\Tests\TestCase;
use PHPUnit\Framework\Assert;
use Symfony\Component\Process\Process;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind a different classes or traits.
|
*/

pest()->extend(TestCase::class)->in(__DIR__);

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

// Custom expectations can be added here if needed

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

/**
 * Fresh operation tables on the SQL server the suite runs against, for the
 * process tests that race separate connections on one shared store. Skipped
 * unless the suite runs on the requested driver. Call down() when done:
 * the tables live in the shared test database, not in a per-test file.
 */
function mcpServerOperationTables(string $driver): object
{
    if (config('database.default') !== $driver) {
        Assert::markTestSkipped("Needs the suite on DB_CONNECTION={$driver}, as in its CI job.");
    }
    $migration = require __DIR__ . '/../database/migrations/operations/0001_create_mcp_operations_table.php';
    $migration->down();
    $migration->up();

    return $migration;
}

/**
 * Waits until a worker process has printed "ready". Symfony Process 7.4's
 * waitUntil() reads the pipes once before its loop without handing that output
 * to the callback, so a worker that is already ready hangs it until the
 * timeout. The buffered output is cumulative, so polling it loses nothing.
 */
function waitForWorkerReady(Process $worker, float $seconds = 20.0): bool
{
    $deadline = microtime(true) + $seconds;
    while (! str_contains($worker->getOutput(), "ready\n")) {
        if (! $worker->isRunning()) {
            return str_contains($worker->getOutput(), "ready\n");
        }
        if (microtime(true) > $deadline) {
            return false;
        }
        usleep(2_000);
    }

    return true;
}

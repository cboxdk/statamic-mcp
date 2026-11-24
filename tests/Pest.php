<?php

use Cboxdk\StatamicMcp\Tests\TestCase;
use PHPUnit\Framework\Assert;

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
 * Fresh operation tables on the MySQL server the suite runs against, for the
 * process tests that race separate connections on one shared store. Skipped
 * unless the suite runs on MySQL (the MySQL CI job). Call down() when done:
 * the tables live in the shared test database, not in a per-test file.
 */
function mcpMysqlOperationTables(): object
{
    if (config('database.default') !== 'mysql') {
        Assert::markTestSkipped('Needs the suite on MySQL (DB_CONNECTION=mysql), as in the MySQL CI job.');
    }
    $migration = require __DIR__ . '/../database/migrations/operations/0001_create_mcp_operations_table.php';
    $migration->down();
    $migration->up();

    return $migration;
}

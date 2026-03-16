<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Tests\Unit\Storage\Tokens;

use Cboxdk\StatamicMcp\Contracts\TokenStore;
use Cboxdk\StatamicMcp\Storage\Tokens\DatabaseTokenStore;
use Cboxdk\StatamicMcp\Tests\Concerns\TokenStoreContractTests;
use Cboxdk\StatamicMcp\Tests\TestCase;

class DatabaseTokenStoreTest extends TestCase
{
    use TokenStoreContractTests;

    protected function createStore(): TokenStore
    {
        return new DatabaseTokenStore;
    }

    protected function setUp(): void
    {
        parent::setUp();

        // Run migrations in correct order (loadMigrationsFrom uses alphabetical
        // order which would run add_unique before create).
        $create = include __DIR__ . '/../../../../database/migrations/tokens/create_mcp_tokens_table.php';
        $create->up();

        $addIndex = include __DIR__ . '/../../../../database/migrations/tokens/add_unique_token_index_to_mcp_tokens_table.php';
        $addIndex->up();
    }
}

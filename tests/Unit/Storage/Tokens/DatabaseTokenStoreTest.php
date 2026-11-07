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

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__ . '/../../../../database/migrations/tokens');
    }
}

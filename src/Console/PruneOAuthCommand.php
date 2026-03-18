<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Console;

use Cboxdk\StatamicMcp\OAuth\Contracts\OAuthDriver;
use Illuminate\Console\Command;

class PruneOAuthCommand extends Command
{
    protected $signature = 'mcp:prune-oauth';

    protected $description = 'Prune expired OAuth clients and used/expired authorization codes';

    public function handle(OAuthDriver $driver): int
    {
        $count = $driver->prune();
        $this->info("Pruned {$count} OAuth entries.");

        return self::SUCCESS;
    }
}

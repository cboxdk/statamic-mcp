<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Console;

use Cboxdk\StatamicMcp\Auth\TokenService;
use Illuminate\Console\Command;

class PruneExpiredTokensCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'mcp:prune-tokens';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Delete expired MCP API tokens';

    public function handle(TokenService $tokenService): int
    {
        $count = $tokenService->pruneExpired();

        if ($count > 0) {
            $this->info("Pruned {$count} expired MCP token(s).");
        } else {
            $this->info('No expired MCP tokens found.');
        }

        return self::SUCCESS;
    }
}

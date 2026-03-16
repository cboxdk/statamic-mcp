<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Console;

use Carbon\Carbon;
use Cboxdk\StatamicMcp\Contracts\AuditStore;
use Illuminate\Console\Command;

class PruneAuditCommand extends Command
{
    /** @var string */
    protected $signature = 'mcp:prune-audit {--days=30 : Number of days to keep} {--all : Purge all entries}';

    /** @var string */
    protected $description = 'Prune old MCP audit log entries';

    public function handle(AuditStore $store): int
    {
        $before = $this->option('all') ? null : Carbon::now()->subDays((int) $this->option('days'));

        $count = $store->purge($before);

        $this->info("Pruned {$count} audit log entries.");

        return self::SUCCESS;
    }
}

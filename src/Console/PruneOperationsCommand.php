<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Console;

use Cboxdk\StatamicMcp\Actions\Contracts\OperationStore;
use Illuminate\Console\Command;

final class PruneOperationsCommand extends Command
{
    protected $signature = 'mcp:prune-operations {--limit=100 : Maximum expired records to remove (1–1000)}';

    protected $description = 'Prune expired operations, retaining executing and unknown outcomes';

    public function handle(OperationStore $store): int
    {
        $limit = $this->option('limit');
        if (! is_string($limit) || ! preg_match('/^[1-9][0-9]{0,3}$/D', $limit) || (int) $limit > 1000) {
            $this->error('The limit must be between 1 and 1000.');

            return self::INVALID;
        }
        $count = $store->prune((int) $limit);
        $this->info("Pruned {$count} expired operations. Executing and unknown outcomes were retained.");

        return self::SUCCESS;
    }
}

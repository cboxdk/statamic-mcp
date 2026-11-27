<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Console;

use Cboxdk\StatamicMcp\Actions\Contracts\OperationStore;
use Illuminate\Console\Command;

final class RepairOperationCapacityCommand extends Command
{
    protected $signature = 'mcp:repair-operation-capacity';

    protected $description = 'Recount occupied operation slots without changing operation outcomes';

    public function handle(OperationStore $store): int
    {
        $count = $store->reconcileCapacity();
        $this->info("Capacity reconciled: {$count} occupied slots. No operation outcomes were changed.");

        return self::SUCCESS;
    }
}

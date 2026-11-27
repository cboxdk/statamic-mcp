<?php

declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';

require __DIR__ . '/operation-store.php';

use Cboxdk\StatamicMcp\Actions\Operations\Operation;
use Cboxdk\StatamicMcp\Actions\Operations\OperationState;

// argv: driver (file|database|mysql), store path
$store = mcpWorkerOperationStore($argv[1], $argv[2], 1);
fwrite(STDOUT, "ready\n");
fflush(STDOUT);
if (fgets(STDIN) !== "create\n") {
    exit(2);
}
try {
    $operation = new Operation(bin2hex(random_bytes(32)), hash('sha256', 'binding'), OperationState::Prepared, 1, time() + 300);
    $store->create($operation);
    echo 'admitted';
} catch (RuntimeException $error) {
    if (! str_contains($error->getMessage(), 'storage is full')) {
        throw $error;
    }
    echo 'full';
}

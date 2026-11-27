<?php

declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';

require __DIR__ . '/operation-store.php';

use Cboxdk\StatamicMcp\Actions\Operations\OperationState;

// argv: SQLite path, operation id, optional driver (database|mysql)
$store = mcpWorkerOperationStore($argv[3] ?? 'database', $argv[1]);
$operation = $store->find($argv[2]);
if ($operation === null || $operation->state !== OperationState::Ready) {
    echo 'lost';
    exit(0);
}
// Both workers must hold the same version before either can claim it.
fwrite(STDOUT, "ready\n");
fflush(STDOUT);
if (fgets(STDIN) !== "claim\n") {
    exit(2);
}
if ($store->transition($operation, $operation->transition(OperationState::Executing))) {
    echo 'claimed';
    exit(9); // Simulate a crash after the durable claim, before completion.
}
echo 'lost';

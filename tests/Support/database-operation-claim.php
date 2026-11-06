<?php

declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';

use Cboxdk\StatamicMcp\Actions\Operations\DatabaseOperationStore;
use Cboxdk\StatamicMcp\Actions\Operations\OperationState;
use Illuminate\Database\SQLiteConnection;

$pdo = new PDO('sqlite:' . $argv[1]);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('PRAGMA busy_timeout = 5000');
$store = new DatabaseOperationStore(new SQLiteConnection($pdo));
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

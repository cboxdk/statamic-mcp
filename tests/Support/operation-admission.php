<?php

declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';

use Cboxdk\StatamicMcp\Actions\Operations\DatabaseOperationStore;
use Cboxdk\StatamicMcp\Actions\Operations\FileOperationStore;
use Cboxdk\StatamicMcp\Actions\Operations\Operation;
use Cboxdk\StatamicMcp\Actions\Operations\OperationState;
use Illuminate\Database\SQLiteConnection;

if ($argv[1] === 'database') {
    $pdo = new PDO('sqlite:' . $argv[2]);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec('PRAGMA busy_timeout = 5000');
    $store = new DatabaseOperationStore(new SQLiteConnection($pdo), 1);
} else {
    $store = new FileOperationStore($argv[2], 1);
}
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

<?php

declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';

use Cboxdk\StatamicMcp\Actions\Operations\FileOperationStore;
use Cboxdk\StatamicMcp\Actions\Operations\OperationState;

$store = new FileOperationStore($argv[1]);
$operation = $store->find($argv[2]);
if ($operation === null || $operation->state !== OperationState::Ready) {
    echo 'lost';
    exit(0);
}
if ($store->transition($operation, $operation->transition(OperationState::Executing))) {
    echo 'claimed';
    exit(($argv[3] ?? '') === 'crash' ? 9 : 0);
}
echo 'lost';

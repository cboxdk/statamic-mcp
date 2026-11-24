<?php

declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';
require __DIR__ . '/operation-store.php';

use Cboxdk\StatamicMcp\Actions\ActionEffect;
use Cboxdk\StatamicMcp\Actions\Operations\IdempotentWrite;
use Cboxdk\StatamicMcp\Actions\Operations\OperationCoordinator;
use Cboxdk\StatamicMcp\Actions\Operations\OperationIntent;
use Cboxdk\StatamicMcp\Approvals\Approvals;
use Illuminate\Encryption\Encrypter;

// argv: driver (file|database|mysql), store path, effects file, approval id, mode (write|crash)
[, $driver, $path, $effects, $id, $mode] = $argv;

$store = mcpWorkerOperationStore($driver, $path);
$encrypter = new Encrypter(str_repeat('k', 32), 'aes-256-cbc');
$approvals = new Approvals($store, new OperationCoordinator($store, $encrypter), new IdempotentWrite($store, $encrypter), $encrypter);
$intent = OperationIntent::forToolCall(str_repeat('0', 64), 'user:1', 'token:1', 'entries', 'delete', ActionEffect::Delete,
    'default', 'editor', 'http', ['action' => 'delete', 'collection' => 'pages', 'id' => 'one']);

fwrite(STDOUT, "ready\n");
fflush(STDOUT);
if (fgets(STDIN) !== "go\n") {
    exit(2);
}

$outcome = $approvals->execute($id, $intent, null, function () use ($effects, $mode): array {
    file_put_contents($effects, getmypid() . "\n", FILE_APPEND | LOCK_EX);
    usleep(200_000);
    if ($mode === 'crash') {
        exit(9);
    }

    return ['deleted' => true];
}, fn (): ?string => 'committed');

echo $outcome['status'];

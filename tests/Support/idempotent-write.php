<?php

declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';
require __DIR__ . '/operation-store.php';

use Cboxdk\StatamicMcp\Actions\ActionEffect;
use Cboxdk\StatamicMcp\Actions\Operations\IdempotentWrite;
use Illuminate\Encryption\Encrypter;

// argv: store (file|database|mysql), store path, effects file, mode (write|crash)
[, $driver, $path, $effects, $mode] = $argv;

$store = mcpWorkerOperationStore($driver, $path);
$writes = new IdempotentWrite($store, new Encrypter(str_repeat('k', 32), 'aes-256-cbc'));
$intent = IdempotentWrite::intent('retry-key-1', 'user:1', 'token:1', 'entries', 'update', ActionEffect::Edit,
    'default', 'editor', 'http', ['collection' => 'pages', 'id' => 'one', 'data' => ['title' => 'New']]);

// Every worker holds its intent before any of them may write.
fwrite(STDOUT, "ready\n");
fflush(STDOUT);
if (fgets(STDIN) !== "go\n") {
    exit(2);
}

$outcome = $writes->run($intent, function () use ($effects, $mode): array {
    file_put_contents($effects, getmypid() . "\n", FILE_APPEND | LOCK_EX);
    usleep(200_000);
    if ($mode === 'crash') {
        exit(9); // Killed after the write landed, before anything was recorded.
    }

    return ['entry' => ['id' => 'one', 'title' => 'New']];
}, fn (): ?string => 'committed');

echo $outcome['status'];

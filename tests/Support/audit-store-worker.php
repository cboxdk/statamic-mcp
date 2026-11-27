<?php

declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';

use Carbon\Carbon;
use Cboxdk\StatamicMcp\Storage\Audit\FileAuditStore;

[$script, $path, $action] = $argv;
$store = new FileAuditStore($path);
if ($action === 'query') {
    $result = $store->query('statamic-entries', 'success', 2, 10);
    echo json_encode(['total' => $result->total, 'entries' => $result->entries], JSON_THROW_ON_ERROR);
    exit;
}
fwrite(STDOUT, "ready\n");
fflush(STDOUT);
if ($action === 'purge') {
    $store->purge(Carbon::parse('2026-01-01T00:00:00Z'));
} else {
    $store->write(['level' => 'info', 'message' => 'Concurrent append', 'timestamp' => '2026-11-07T00:00:00Z']);
}
fwrite(STDOUT, "done\n");

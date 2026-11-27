<?php

declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';

use Cboxdk\StatamicMcp\Storage\Tokens\FileTokenStore;

[$script, $directory, $id, $action] = $argv;
$store = new FileTokenStore($directory);
fwrite(STDOUT, "ready\n");
fflush(STDOUT);
match ($action) {
    'used' => $store->markAsUsed($id),
    'update' => $store->update($id, ['name' => 'Updated']),
    'delete' => $store->delete($id),
};
fwrite(STDOUT, "done\n");

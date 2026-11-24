<?php

declare(strict_types=1);

use Cboxdk\StatamicMcp\Actions\Contracts\OperationStore;
use Cboxdk\StatamicMcp\Actions\Operations\DatabaseOperationStore;
use Cboxdk\StatamicMcp\Actions\Operations\FileOperationStore;
use Illuminate\Database\MySqlConnection;
use Illuminate\Database\SQLiteConnection;

/**
 * The store a worker process races on: 'file' (a directory), 'database'
 * (a SQLite file) or 'mysql' (the DB_* server the suite runs against; the
 * path is ignored). Each process opens its own connection, as nodes do.
 */
function mcpWorkerOperationStore(string $driver, string $path, int $maxRecords = 256): OperationStore
{
    if ($driver === 'file') {
        return new FileOperationStore($path, $maxRecords);
    }
    if ($driver === 'mysql') {
        $database = (string) getenv('DB_DATABASE');
        $pdo = new PDO(sprintf('mysql:host=%s;port=%s;dbname=%s', getenv('DB_HOST') ?: '127.0.0.1', getenv('DB_PORT') ?: '3306', $database),
            (string) getenv('DB_USERNAME'), (string) getenv('DB_PASSWORD'), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

        return new DatabaseOperationStore(new MySqlConnection($pdo, $database), $maxRecords);
    }
    $pdo = new PDO('sqlite:' . $path);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec('PRAGMA busy_timeout = 5000');

    return new DatabaseOperationStore(new SQLiteConnection($pdo), $maxRecords);
}

<?php

declare(strict_types=1);
use Cboxdk\StatamicMcp\Support\WireInputBudget;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$input = '[' . str_repeat('0,', 1000000) . '0]';
try {
    (new WireInputBudget)->check($input);
    throw new RuntimeException('Oversized object graph was accepted.');
} catch (LengthException) {
    echo json_encode(['rejected' => true, 'peak_bytes' => memory_get_peak_usage(true)], JSON_THROW_ON_ERROR);
}

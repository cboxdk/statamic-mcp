<?php

declare(strict_types=1);

use Cboxdk\StatamicMcp\Storage\Audit\AuditResult;

it('creates an audit result with pagination metadata', function (): void {
    $entries = [
        ['tool' => 'statamic-entries', 'status' => 'success', 'timestamp' => '2026-03-15T12:00:00Z'],
        ['tool' => 'statamic-blueprints', 'status' => 'failed', 'timestamp' => '2026-03-15T12:01:00Z'],
    ];

    $result = new AuditResult(
        entries: $entries,
        total: 50,
        currentPage: 1,
        lastPage: 25,
        perPage: 2,
    );

    expect($result->entries)->toHaveCount(2);
    expect($result->total)->toBe(50);
    expect($result->currentPage)->toBe(1);
    expect($result->lastPage)->toBe(25);
    expect($result->perPage)->toBe(2);
});

it('handles empty results', function (): void {
    $result = new AuditResult(
        entries: [],
        total: 0,
        currentPage: 1,
        lastPage: 1,
        perPage: 25,
    );

    expect($result->entries)->toBeEmpty();
    expect($result->total)->toBe(0);
});

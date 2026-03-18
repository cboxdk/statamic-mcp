<?php

declare(strict_types=1);

use Carbon\Carbon;
use Cboxdk\StatamicMcp\Contracts\AuditStore;
use Cboxdk\StatamicMcp\Mcp\Support\ToolLogger;
use Cboxdk\StatamicMcp\Storage\Audit\FileAuditStore;
use Cboxdk\StatamicMcp\Storage\Tokens\McpTokenData;
use Statamic\Contracts\Auth\User;

/**
 * Get the test log path.
 */
function testLogPath(): string
{
    return storage_path('logs/mcp-test.log');
}

/**
 * Clean up the log file between tests.
 */
function cleanupLogFiles(): void
{
    $logPath = testLogPath();
    if (file_exists($logPath)) {
        unlink($logPath);
    }
}

beforeEach(function () {
    $path = testLogPath();

    // Reset config for each test
    config([
        'statamic.mcp.security.audit_logging' => true,
    ]);

    // Bind a FileAuditStore pointing at the test path
    app()->singleton(AuditStore::class, fn (): FileAuditStore => new FileAuditStore($path));

    cleanupLogFiles();
});

afterEach(function () {
    cleanupLogFiles();
});

it('logs a complete tool call with success status', function () {
    ToolLogger::logToolCall(
        'statamic-entries',
        ['action' => 'list', 'collection' => 'blog'],
        'success',
        durationMs: 42.5,
        action: 'list',
        result: ['success' => true, 'data' => ['entries' => [1, 2, 3]]],
        correlationId: 'test-corr-id',
    );

    $content = file_get_contents(testLogPath());
    expect($content)->toContain('statamic-entries.list: success')
        ->toContain('statamic-entries')
        ->toContain('test-corr-id')
        ->toContain('"status":"success"')
        ->toContain('"action":"list"');

    $decoded = json_decode(trim($content), true);
    expect($decoded)->toBeArray()
        ->toHaveKey('tool', 'statamic-entries')
        ->toHaveKey('status', 'success')
        ->toHaveKey('action', 'list')
        ->toHaveKey('duration_ms', 42.5)
        ->toHaveKey('correlation_id', 'test-corr-id')
        ->toHaveKey('arguments')
        ->toHaveKey('response_summary');

    expect($decoded['response_summary'])->toBe('Listed 3 entries');
});

it('logs a tool call with error status', function () {
    ToolLogger::logToolCall(
        'statamic-blueprints',
        ['action' => 'get', 'handle' => 'post'],
        'error',
        durationMs: 15.3,
        action: 'get',
        error: new RuntimeException('Blueprint not found'),
        correlationId: 'err-corr-id',
    );

    $content = file_get_contents(testLogPath());
    $decoded = json_decode(trim($content), true);

    expect($decoded)->toBeArray()
        ->toHaveKey('level', 'error')
        ->toHaveKey('status', 'error')
        ->toHaveKey('tool', 'statamic-blueprints')
        ->toHaveKey('error');

    expect($decoded['error'])->toHaveKey('message', 'Blueprint not found')
        ->toHaveKey('class', 'RuntimeException');
});

it('logs a tool call with validation_error status', function () {
    ToolLogger::logToolCall(
        'statamic-entries',
        ['action' => 'create'],
        'validation_error',
        durationMs: 5.0,
        action: 'create',
        error: new InvalidArgumentException('Missing required field: title'),
        correlationId: 'val-corr-id',
    );

    $content = file_get_contents(testLogPath());
    $decoded = json_decode(trim($content), true);

    expect($decoded)->toBeArray()
        ->toHaveKey('level', 'error')
        ->toHaveKey('status', 'validation_error')
        ->toHaveKey('error');

    expect($decoded['error']['message'])->toBe('Missing required field: title');
});

it('logs a tool call with timeout status', function () {
    ToolLogger::logToolCall(
        'statamic-entries',
        ['action' => 'list'],
        'timeout',
        durationMs: 31000.0,
        action: 'list',
        correlationId: 'timeout-corr-id',
    );

    $content = file_get_contents(testLogPath());
    $decoded = json_decode(trim($content), true);

    expect($decoded)->toBeArray()
        ->toHaveKey('status', 'timeout')
        ->toHaveKey('level', 'error')
        ->toHaveKey('duration_ms', 31000.0);
});

it('builds correct message with action', function () {
    ToolLogger::logToolCall(
        'statamic-entries',
        ['action' => 'create'],
        'success',
        action: 'create',
    );

    $content = file_get_contents(testLogPath());
    expect($content)->toContain('statamic-entries.create: success');
});

it('builds correct message without action', function () {
    ToolLogger::logToolCall(
        'statamic-system',
        [],
        'error',
        error: new RuntimeException('System failure'),
    );

    $content = file_get_contents(testLogPath());
    expect($content)->toContain('statamic-system: error');
});

it('captures user context from request attributes', function () {
    $mockUser = Mockery::mock(User::class);
    $mockUser->shouldReceive('email')->andReturn('admin@site.dk');
    $mockUser->shouldReceive('name')->andReturn('Admin');
    $mockUser->shouldReceive('id')->andReturn('user-1');

    $mockToken = new McpTokenData(
        id: 'token-1', userId: 'user-1', name: 'Claude API Token',
        tokenHash: 'hash', scopes: ['*'], lastUsedAt: null,
        expiresAt: null, createdAt: Carbon::now(),
    );

    request()->attributes->set('statamic_user', $mockUser);
    request()->attributes->set('mcp_token', $mockToken);

    ToolLogger::logToolCall(
        'statamic-entries',
        ['action' => 'list'],
        'success',
        action: 'list',
    );

    $content = file_get_contents(testLogPath());
    $decoded = json_decode(trim($content), true);

    expect($decoded)->toHaveKey('user_id', 'user-1')
        ->toHaveKey('user', 'Admin')
        ->toHaveKey('token_name', 'Claude API Token')
        ->toHaveKey('context');
});

it('summarizes response with entries count', function () {
    ToolLogger::logToolCall(
        'statamic-entries',
        ['action' => 'list'],
        'success',
        result: ['success' => true, 'data' => ['entries' => array_fill(0, 10, 'entry')]],
    );

    $content = file_get_contents(testLogPath());
    $decoded = json_decode(trim($content), true);
    expect($decoded['response_summary'])->toBe('Listed 10 entries');
});

it('summarizes response with error message', function () {
    ToolLogger::logToolCall(
        'statamic-entries',
        ['action' => 'get'],
        'error',
        result: ['success' => false, 'error' => 'Entry not found'],
    );

    $content = file_get_contents(testLogPath());
    $decoded = json_decode(trim($content), true);
    expect($decoded['response_summary'])->toBe('Entry not found');
});

it('summarizes response with created flag', function () {
    ToolLogger::logToolCall(
        'statamic-entries',
        ['action' => 'create'],
        'success',
        result: ['created' => true],
    );

    $content = file_get_contents(testLogPath());
    $decoded = json_decode(trim($content), true);
    expect($decoded['response_summary'])->toBe('Created successfully');
});

it('summarizes response with handle retrieval', function () {
    ToolLogger::logToolCall(
        'statamic-blueprints',
        ['action' => 'get'],
        'success',
        result: ['success' => true, 'data' => ['handle' => 'post']],
    );

    $content = file_get_contents(testLogPath());
    $decoded = json_decode(trim($content), true);
    expect($decoded['response_summary'])->toBe("Retrieved 'post'");
});

it('redacts sensitive arguments', function () {
    ToolLogger::logToolCall(
        'statamic-users',
        [
            'action' => 'create',
            'name' => 'Test User',
            'password' => 'secret123',
            'api_key' => 'sk-abc',
            'authorization' => 'Bearer token',
            'email' => 'user@example.com',
        ],
        'success',
    );

    $content = file_get_contents(testLogPath());
    expect($content)->toContain('Test User')
        ->toContain('[REDACTED]')
        ->not->toContain('secret123')
        ->not->toContain('sk-abc')
        ->not->toContain('Bearer token')
        ->not->toContain('user@example.com');
});

it('redacts values containing email addresses regardless of key name', function () {
    ToolLogger::logToolCall(
        'statamic-entries',
        [
            'action' => 'create',
            'author_info' => 'Contact me at john@example.com',
            'custom_data' => 'Safe value without PII',
        ],
        'success',
    );

    $content = file_get_contents(testLogPath());
    expect($content)->toContain('Safe value without PII')
        ->toContain('[REDACTED]')
        ->not->toContain('john@example.com');
});

it('redacts values containing auth tokens regardless of key name', function () {
    ToolLogger::logToolCall(
        'statamic-entries',
        [
            'action' => 'list',
            'raw_header' => 'Bearer eyJhbGciOiJIUzI1NiJ9.payload.sig',
            'normal_field' => 'hello',
        ],
        'success',
    );

    $content = file_get_contents(testLogPath());
    expect($content)->toContain('hello')
        ->not->toContain('eyJhbGciOiJIUzI1NiJ9');
});

it('skips logging when audit logging is disabled', function () {
    config(['statamic.mcp.security.audit_logging' => false]);

    ToolLogger::logToolCall(
        'statamic-entries',
        ['action' => 'list'],
        'success',
    );

    expect(file_exists(testLogPath()))->toBeFalse();
});

it('reports enabled status from config', function () {
    config(['statamic.mcp.security.audit_logging' => true]);
    expect(ToolLogger::isEnabled())->toBeTrue();

    config(['statamic.mcp.security.audit_logging' => false]);
    expect(ToolLogger::isEnabled())->toBeFalse();
});

it('writes valid JSONL that can be parsed', function () {
    ToolLogger::logToolCall(
        'statamic-entries',
        ['action' => 'list'],
        'success',
        durationMs: 100.0,
        action: 'list',
        correlationId: 'jsonl-test',
    );

    $lines = file(testLogPath(), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    expect($lines)->toHaveCount(1);

    $decoded = json_decode($lines[0], true);
    expect($decoded)->toBeArray()
        ->toHaveKeys(['level', 'message', 'tool', 'status', 'timestamp']);
});

it('logs optional fields only when provided', function () {
    ToolLogger::logToolCall(
        'statamic-entries',
        ['action' => 'list'],
        'success',
    );

    $content = file_get_contents(testLogPath());
    $decoded = json_decode(trim($content), true);

    // Should not contain optional fields that were not provided
    expect($decoded)->not->toHaveKey('duration_ms');
    expect($decoded)->not->toHaveKey('action');
    expect($decoded)->not->toHaveKey('correlation_id');
    expect($decoded)->not->toHaveKey('response_summary');
    expect($decoded)->not->toHaveKey('error');
});

<?php

declare(strict_types=1);

use Cboxdk\StatamicMcp\Actions\Operations\DatabaseOperationStore;
use Cboxdk\StatamicMcp\Contracts\TokenStore;
use Cboxdk\StatamicMcp\OAuth\Drivers\DatabaseOAuthDriver;
use Cboxdk\StatamicMcp\Storage\Tokens\DatabaseTokenStore;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Support\Facades\Artisan;
use Monolog\Handler\StreamHandler;

function mcpDoctorReport(): array
{
    Artisan::call('mcp:doctor', ['--json' => true]);

    return json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
}

it('reports a read-only preflight without claiming end-to-end verification', function () {
    config(['statamic.mcp.web.enabled' => false]);
    $before = config('statamic.mcp');
    $report = mcpDoctorReport();
    expect($report['read_only'])->toBeTrue()->and($report['schema'])->toBe(1)
        ->and(config('statamic.mcp'))->toBe($before);
    $checks = collect($report['checks'])->keyBy('id');
    expect($checks['storage.verification']['status'])->toBe('warn')
        ->and($checks['migration.consent']['status'])->toBe('warn');
});

it('rejects remote deployment hazards without printing the supplied secrets', function () {
    config(['statamic.mcp.web.enabled' => true, 'app.url' => 'https://credential-secret@example.test',
        'statamic.mcp.profiles.http' => null, 'statamic.mcp.web.require_https' => false,
        'statamic.mcp.web.allowed_origins' => ['*'], 'statamic.mcp.security.url_import.block_private_ips' => false,
        'statamic.mcp.stdio.token' => 'private-token-secret']);
    expect(Artisan::call('mcp:doctor', ['--json' => true]))->toBe(1);
    $output = Artisan::output();
    expect($output)->not->toContain('credential-secret')->not->toContain('private-token-secret');
    $checks = collect(json_decode($output, true, flags: JSON_THROW_ON_ERROR)['checks'])->keyBy('id');
    foreach (['http.canonical_url', 'profile.http', 'http.require_https', 'http.origins', 'security.url_import.block_private_ips'] as $id) {
        expect($checks[$id]['status'])->toBe('fail');
    }
});

it('does not mistake a truthy non-boolean HTTP setting for disabled transport', function () {
    config(['statamic.mcp.web.enabled' => 'true', 'app.url' => 'http://example.test']);
    $report = mcpDoctorReport();
    $checks = collect($report['checks'])->keyBy('id');
    expect($report['ok'])->toBeFalse()->and($checks['transport.http_config']['status'])->toBe('fail')
        ->and($checks['http.canonical_url']['status'])->toBe('fail');
});

it('refuses local security stores for an explicitly shared deployment', function () {
    config(['statamic.mcp.operations.multi_node' => true]);
    $checks = collect(mcpDoctorReport()['checks'])->keyBy('id');
    expect($checks['storage.multi_node']['status'])->toBe('fail');
    config(['statamic.mcp.stores.tokens' => DatabaseTokenStore::class,
        'statamic.mcp.stores.operations' => DatabaseOperationStore::class,
        'statamic.mcp.oauth.driver' => DatabaseOAuthDriver::class]);
    $checks = collect(mcpDoctorReport()['checks'])->keyBy('id');
    expect($checks['storage.multi_node']['status'])->toBe('warn');
});

it('fails unsupported durable audit and revocation contracts without resolving their drivers', function () {
    config(['statamic.mcp.stores.audit' => stdClass::class, 'statamic.mcp.oauth.driver' => stdClass::class]);
    $checks = collect(mcpDoctorReport()['checks'])->keyBy('id');
    expect($checks['storage.audit_contract']['status'])->toBe('fail')
        ->and($checks['storage.oauth_revocation']['status'])->toBe('fail');
});

it('flags non-origin URLs and local locks without testing external connectivity', function () {
    config(['statamic.mcp.web.enabled' => true, 'statamic.mcp.web.allowed_origins' => ['https://example.test/path'],
        'statamic.mcp.operations.multi_node' => true, 'statamic.mcp.oauth.lock_store' => 'array']);
    $checks = collect(mcpDoctorReport()['checks'])->keyBy('id');
    expect($checks['http.origins']['status'])->toBe('fail')
        ->and($checks['storage.shared_lock']['status'])->toBe('fail');
});

it('fails debug mode on a public endpoint and warns when every proxy is trusted', function () {
    config(['statamic.mcp.web.enabled' => true, 'app.debug' => true]);
    TrustProxies::at('*');
    try {
        $checks = collect(mcpDoctorReport()['checks'])->keyBy('id');
    } finally {
        TrustProxies::flushState();
    }
    expect($checks['http.debug']['status'])->toBe('fail')
        ->and($checks['http.trusted_proxies']['status'])->toBe('warn');

    config(['app.debug' => false]);
    TrustProxies::at(['10.0.0.1']);
    try {
        $checks = collect(mcpDoctorReport()['checks'])->keyBy('id');
    } finally {
        TrustProxies::flushState();
    }
    expect($checks['http.debug']['status'])->toBe('pass')
        ->and($checks['http.trusted_proxies']['status'])->toBe('pass');
});

it('fails a stdout log channel when stdio is configured, through a stack', function () {
    config(['statamic.mcp.profiles.stdio' => 'editor', 'logging.default' => 'stack',
        'logging.channels.stack' => ['driver' => 'stack', 'channels' => ['single', 'console']],
        'logging.channels.console' => ['driver' => 'monolog', 'handler' => StreamHandler::class, 'with' => ['stream' => 'php://stdout']],
        'logging.deprecations.channel' => 'null']);
    $check = collect(mcpDoctorReport()['checks'])->keyBy('id')['stdio.stdout'];
    expect($check['status'])->toBe('fail')->and($check['message'])->toContain('console')->not->toContain('single');

    // stderr is where diagnostics belong.
    config(['logging.channels.console.with.stream' => 'php://stderr']);
    expect(collect(mcpDoctorReport()['checks'])->keyBy('id')['stdio.stdout']['status'])->toBe('pass');

    // Without a stdio profile the server never runs over stdio: only a warning.
    config(['logging.channels.console.with.stream' => 'php://stdout', 'statamic.mcp.profiles.stdio' => null]);
    expect(collect(mcpDoctorReport()['checks'])->keyBy('id')['stdio.stdout']['status'])->toBe('warn');
});

it('counts tokens that outlive the lifetime cap and tokens carrying a retired content scope', function () {
    config(['statamic.mcp.security.max_token_lifetime_days' => 30]);
    $store = app(TokenStore::class);
    $store->create('u1', 'Old forever', hash('sha256', 'a'), ['entries:read'], null);
    $store->create('u1', 'Old long', hash('sha256', 'b'), ['content:read', 'entries:read'], now()->addYear());
    $store->create('u1', 'Fine', hash('sha256', 'c'), ['entries:read'], now()->addDays(10));

    $checks = collect(mcpDoctorReport()['checks'])->keyBy('id');
    expect($checks['tokens.lifetime_cap']['status'])->toBe('warn')
        ->and($checks['tokens.lifetime_cap']['message'])->toStartWith('2 token(s)')
        ->and($checks['migration.inert_scopes']['status'])->toBe('warn')
        ->and($checks['migration.inert_scopes']['message'])->toStartWith('1 token(s)');

    config(['statamic.mcp.security.max_token_lifetime_days' => null]);
    expect(collect(mcpDoctorReport()['checks'])->keyBy('id'))->not->toHaveKey('tokens.lifetime_cap');
});

it('warns when approvals are on but Statamic elevated sessions are off', function () {
    config(['statamic.mcp.confirmation.enabled' => true, 'statamic.users.elevated_sessions_enabled' => false]);
    expect(collect(mcpDoctorReport()['checks'])->keyBy('id')['approvals.elevated_session']['status'])->toBe('warn');

    config(['statamic.users.elevated_sessions_enabled' => true]);
    expect(collect(mcpDoctorReport()['checks'])->keyBy('id')['approvals.elevated_session']['status'])->toBe('pass');

    config(['statamic.mcp.confirmation.enabled' => false]);
    expect(collect(mcpDoctorReport()['checks'])->keyBy('id'))->not->toHaveKey('approvals.elevated_session');
});

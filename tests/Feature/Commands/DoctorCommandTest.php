<?php

declare(strict_types=1);

use Cboxdk\StatamicMcp\Actions\Operations\DatabaseOperationStore;
use Cboxdk\StatamicMcp\OAuth\Drivers\DatabaseOAuthDriver;
use Cboxdk\StatamicMcp\Storage\Tokens\DatabaseTokenStore;
use Illuminate\Support\Facades\Artisan;

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

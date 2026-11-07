<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Console;

use Cboxdk\StatamicMcp\Actions\Operations\FileOperationStore;
use Cboxdk\StatamicMcp\Contracts\DurableAuditStore;
use Cboxdk\StatamicMcp\OAuth\Contracts\OAuthDriver;
use Cboxdk\StatamicMcp\OAuth\Drivers\BuiltInOAuthDriver;
use Cboxdk\StatamicMcp\Storage\Tokens\FileTokenStore;
use Illuminate\Console\Command;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Support\Facades\Cache;

/** Read-only configuration preflight, not a proof of deployment or release safety. */
final class DoctorCommand extends Command
{
    protected $signature = 'mcp:doctor {--json : Print a machine-readable report without configuration values}';

    protected $description = 'Check MCP transport and security storage configuration without changing state';

    public function handle(): int
    {
        $checks = [];
        $add = static function (string $id, string $status, string $message) use (&$checks): void {
            $checks[] = ['id' => $id, 'status' => $status, 'message' => $message];
        };
        $enabled = config('statamic.mcp.web.enabled');
        $add('transport.http_config', is_bool($enabled) ? 'pass' : 'fail', is_bool($enabled)
            ? 'HTTP enablement is an explicit boolean.' : 'Set HTTP enablement to a boolean; ambiguous values are unsafe.');
        $http = $enabled !== false;
        $add('transport.http', 'pass', $http ? 'HTTP is explicitly enabled.' : 'HTTP is disabled.');
        foreach (['http', 'stdio'] as $transport) {
            $profile = config("statamic.mcp.profiles.{$transport}");
            $valid = in_array($profile, ['read_only', 'editor', 'operator'], true);
            $add("profile.{$transport}", $valid ? 'pass' : ($transport === 'http' && $http ? 'fail' : 'warn'),
                $valid ? 'An explicit supported profile is configured.' : 'No supported profile is selected; this transport cannot execute registered actions.');
        }
        $stdioToken = config('statamic.mcp.stdio.token');
        $hasStdioToken = is_string($stdioToken) && $stdioToken !== '';
        $add('stdio.credential', $hasStdioToken ? 'pass' : 'warn', $hasStdioToken
            ? 'A stdio credential is configured; validity was not tested.' : 'Registered stdio actions require an explicitly configured credential.');
        if ($http) {
            $url = config('app.url');
            $parts = is_string($url) ? parse_url($url) : false;
            $canonical = is_array($parts) && ($parts['scheme'] ?? null) === 'https'
                && isset($parts['host']) && ! isset($parts['user']) && ! isset($parts['pass'])
                && ! isset($parts['query']) && ! isset($parts['fragment']);
            $add('http.canonical_url', $canonical ? 'pass' : 'fail', $canonical
                ? 'A canonical HTTPS URL is configured.' : 'Set APP_URL to the public HTTPS URL without credentials, query or fragment.');
            $https = config('statamic.mcp.web.require_https') === true;
            $add('http.require_https', $https ? 'pass' : 'fail', $https
                ? 'HTTPS enforcement is enabled.' : 'Enable HTTPS enforcement before exposing HTTP MCP.');
            $origins = config('statamic.mcp.web.allowed_origins');
            $restricted = is_array($origins) && array_is_list($origins);
            if ($restricted) {
                foreach ($origins as $origin) {
                    if (! is_string($origin) || filter_var($origin, FILTER_VALIDATE_URL) === false || ! preg_match('~^https://[^/?#@\\s]+$~D', $origin)) {
                        $restricted = false;
                        break;
                    }
                }
            }
            $add('http.origins', $restricted ? 'pass' : 'fail', $restricted
                ? 'CORS is disabled or restricted to configured origins; review those origins.' : 'Use an explicit origin list instead of wildcard or malformed configuration.');
            $add('http.proxy', 'warn', 'Verify trusted proxies and externally observed HTTPS/Origin handling at the actual ingress; local configuration cannot prove it.');
        }
        foreach (['security.url_import.block_private_ips', 'oauth.cimd_block_private_ips'] as $key) {
            $blocked = config("statamic.mcp.{$key}") === true;
            $add($key, $blocked ? 'pass' : 'fail', $blocked
                ? 'Private network fetches are blocked.' : 'Restore private-network blocking before accepting remote input.');
        }
        foreach (['max_input_bytes', 'max_input_values', 'max_input_depth'] as $key) {
            $value = config("statamic.mcp.security.{$key}");
            $valid = is_int($value) && $value > 0;
            $add("budget.{$key}", $valid ? 'pass' : 'fail', $valid
                ? 'A positive input budget is configured.' : 'Configure a positive integer input budget.');
        }
        $audit = config('statamic.mcp.stores.audit');
        $durable = is_string($audit) && is_a($audit, DurableAuditStore::class, true);
        $add('storage.audit_contract', $durable ? 'pass' : 'fail', $durable
            ? 'The configured audit driver declares durable admission support.' : 'Registered writes require a durable audit driver.');
        $oauth = config('statamic.mcp.oauth.driver');
        $revokes = is_string($oauth) && is_a($oauth, OAuthDriver::class, true);
        $add('storage.oauth_revocation', $revokes ? 'pass' : 'fail', $revokes
            ? 'The configured OAuth driver supports user-grant revocation.' : 'The OAuth driver must support user-grant revocation before migration.');
        if (config('statamic.mcp.operations.multi_node') === true) {
            $local = config('statamic.mcp.stores.operations') === FileOperationStore::class
                || config('statamic.mcp.stores.tokens') === FileTokenStore::class
                || $oauth === BuiltInOAuthDriver::class;
            $add('storage.multi_node', $local ? 'fail' : 'warn', $local
                ? 'Multi-node operation requires shared security stores; local file drivers are configured.'
                : 'Verify every security store and lock is shared by all nodes; driver names alone do not prove this.');
        }
        try {
            $name = config('statamic.mcp.oauth.lock_store');
            $storeName = $name ?? config('cache.default');
            $localLock = is_string($storeName) && in_array(config("cache.stores.{$storeName}.driver"), ['array', 'file', 'null'], true);
            if (config('statamic.mcp.operations.multi_node') === true && $localLock) {
                $add('storage.shared_lock', 'fail', 'Select a shared lock backend for multi-node OAuth mutations; a local cache lock is insufficient.');
            }
            $locks = (is_string($name) || $name === null) && Cache::store($name)->getStore() instanceof LockProvider;
            $add('storage.lock_contract', $locks ? 'pass' : 'fail', $locks
                ? 'The configured cache driver declares atomic-lock support; reachability was not tested.' : 'Select an OAuth cache store with atomic-lock support.');
        } catch (\Throwable) {
            $add('storage.lock_contract', 'fail', 'The configured lock store could not be resolved. Details are omitted to protect credentials.');
        }
        $add('migration.consent', 'warn', 'Legacy broad scopes require explicit v4 consent. This command does not migrate tokens, approvals or content.');
        $add('storage.verification', 'warn', 'Check protected storage paths, committed SQL connections, retention jobs and crash recovery separately; no write or lock probe was performed.');
        $failed = count(array_filter($checks, static fn (array $check): bool => $check['status'] === 'fail'));
        if ($this->option('json')) {
            $this->line(json_encode(['schema' => 1, 'read_only' => true, 'ok' => $failed === 0, 'checks' => $checks], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
        } else {
            $this->table(['Check', 'Status', 'Action'], array_map(static fn (array $check): array => array_values($check), $checks));
            $this->line('Configuration preflight only. A passing report does not certify release readiness or end-to-end security.');
        }

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}

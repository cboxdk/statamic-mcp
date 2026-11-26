<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Console;

use Cboxdk\StatamicMcp\Actions\Operations\FileOperationStore;
use Cboxdk\StatamicMcp\Actions\ScopeRegistry;
use Cboxdk\StatamicMcp\Auth\ConfirmationActionGate;
use Cboxdk\StatamicMcp\Auth\TokenScope;
use Cboxdk\StatamicMcp\Auth\TokenService;
use Cboxdk\StatamicMcp\Contracts\DurableAuditStore;
use Cboxdk\StatamicMcp\Contracts\TokenStore;
use Cboxdk\StatamicMcp\OAuth\Contracts\OAuthDriver;
use Cboxdk\StatamicMcp\OAuth\Drivers\BuiltInOAuthDriver;
use Cboxdk\StatamicMcp\Storage\Tokens\FileTokenStore;
use Illuminate\Console\Command;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Http\Middleware\TrustProxies;
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
            $disabled = ($profile === null || $profile === '') && ($transport !== 'http' || ! $http);
            $key = 'STATAMIC_MCP_' . strtoupper($transport) . '_PROFILE';
            $add("profile.{$transport}", $valid || $disabled ? 'pass' : 'fail',
                $valid ? 'An explicit supported profile is configured.' : ($disabled ? 'This transport is disabled.' : "Set {$key}=read_only, editor or operator."));
        }
        $stdioToken = config('statamic.mcp.stdio.token');
        $hasStdioToken = is_string($stdioToken) && $stdioToken !== '';
        $needsCredential = config('statamic.mcp.profiles.stdio') === 'operator' && app(ScopeRegistry::class)->names() !== [];
        $add('stdio.credential', $hasStdioToken || ! $needsCredential ? 'pass' : 'warn', $hasStdioToken
            ? 'A stdio credential is configured; validity was not tested.' : ($needsCredential
                ? 'Integration actions need STATAMIC_MCP_STDIO_TOKEN with current consent. Create it in Tools → MCP; built-in local tools do not need it.'
                : 'The configured local built-in tools do not need STATAMIC_MCP_STDIO_TOKEN.'));
        $stdout = self::stdoutLogChannels();
        $stdio = in_array(config('statamic.mcp.profiles.stdio'), ['read_only', 'editor', 'operator'], true);
        $add('stdio.stdout', $stdout === [] || ! $stdio ? 'pass' : 'fail', $stdout === [] || ! $stdio
            ? ($stdio ? 'No log channel the server uses writes to stdout, which carries only protocol messages over stdio.' : 'Stdio is disabled; stdout logging cannot corrupt its protocol.')
            : 'Log channel(s) ' . implode(', ', $stdout) . ' write to stdout and would corrupt the stdio protocol. Set LOG_CHANNEL=stderr or configure logging.channels to use a file.');
        if ($http) {
            $url = config('app.url');
            $parts = is_string($url) ? parse_url($url) : false;
            $localHttp = app()->environment('local', 'testing') && is_array($parts) && ($parts['scheme'] ?? null) === 'http'
                && in_array($parts['host'] ?? null, ['localhost', '127.0.0.1', '[::1]'], true);
            $canonical = is_array($parts) && (($parts['scheme'] ?? null) === 'https' || $localHttp)
                && isset($parts['host']) && ! isset($parts['user']) && ! isset($parts['pass'])
                && ! isset($parts['query']) && ! isset($parts['fragment']);
            $add('http.canonical_url', $canonical ? 'pass' : 'fail', $canonical
                ? ($localHttp ? 'APP_URL is loopback HTTP in a local/test environment.' : 'A canonical HTTPS URL is configured.') : 'Set APP_URL to the public HTTPS URL without credentials, query or fragment.');
            $https = config('statamic.mcp.web.require_https') === true;
            $add('http.require_https', $https || $localHttp ? 'pass' : 'fail', $https || $localHttp
                ? 'HTTPS enforcement is enabled or HTTP is local loopback only.' : 'Set STATAMIC_MCP_WEB_REQUIRE_HTTPS=true before exposing HTTP MCP.');
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
                ? 'CORS is disabled or restricted to configured origins; review those origins.' : 'Set statamic.mcp.web.allowed_origins to an explicit origin list, never a wildcard.');
            $debug = config('app.debug') === true;
            $add('http.debug', $debug && ! $localHttp ? 'fail' : 'pass', $debug && ! $localHttp
                ? 'Debug mode is on: error pages over HTTP expose stack traces and configuration. Set APP_DEBUG=false.' : 'Debug mode is off or limited to local loopback.');
            $proxies = self::trustedProxies();
            $everyProxy = $proxies === '*' || $proxies === '**' || (is_array($proxies) && array_intersect($proxies, ['*', '**', '0.0.0.0/0', '::/0']) !== []);
            $add('http.trusted_proxies', $everyProxy ? 'warn' : 'pass', $everyProxy
                ? 'Every proxy is trusted. Configure trustProxies in bootstrap/app.php for your ingress only, unless the app is reachable through it alone.'
                : 'Forwarded headers are trusted only from configured proxies, if any.');
        }
        foreach (['security.url_import.block_private_ips', 'oauth.cimd_block_private_ips'] as $key) {
            $blocked = config("statamic.mcp.{$key}") === true;
            $add($key, $blocked ? 'pass' : 'fail', $blocked
                ? 'Private network fetches are blocked.' : "Set statamic.mcp.{$key}=true before accepting remote input.");
        }
        foreach (['max_input_bytes', 'max_input_values', 'max_input_depth'] as $key) {
            $value = config("statamic.mcp.security.{$key}");
            $valid = is_int($value) && $value > 0;
            $add("budget.{$key}", $valid ? 'pass' : 'fail', $valid
                ? 'A positive input budget is configured.' : "Set statamic.mcp.security.{$key} to a positive integer.");
        }
        $audit = config('statamic.mcp.stores.audit');
        $durable = is_string($audit) && is_a($audit, DurableAuditStore::class, true);
        $add('storage.audit_contract', $durable ? 'pass' : 'fail', $durable
            ? 'The configured audit driver declares durable admission support.' : 'Set statamic.mcp.stores.audit to a DurableAuditStore implementation before accepting writes.');
        $oauth = config('statamic.mcp.oauth.driver');
        $revokes = is_string($oauth) && is_a($oauth, OAuthDriver::class, true);
        $add('storage.oauth_revocation', $revokes ? 'pass' : 'fail', $revokes
            ? 'The configured OAuth driver supports user-grant revocation.' : 'Set statamic.mcp.oauth.driver to an OAuthDriver implementation supporting user-grant revocation.');
        if (config('statamic.mcp.operations.multi_node') === true) {
            $local = config('statamic.mcp.stores.operations') === FileOperationStore::class
                || config('statamic.mcp.stores.tokens') === FileTokenStore::class
                || $oauth === BuiltInOAuthDriver::class;
            $add('storage.multi_node', $local ? 'fail' : 'warn', $local
                ? 'statamic.mcp.operations.multi_node requires shared stores: configure statamic.mcp.stores.tokens, statamic.mcp.stores.operations and statamic.mcp.oauth.driver.'
                : 'Verify statamic.mcp.stores and statamic.mcp.oauth.lock_store share storage across nodes; driver names alone do not prove this.');
        }
        try {
            $name = config('statamic.mcp.oauth.lock_store');
            $storeName = $name ?? config('cache.default');
            $localLock = is_string($storeName) && in_array(config("cache.stores.{$storeName}.driver"), ['array', 'file', 'null'], true);
            if (config('statamic.mcp.operations.multi_node') === true && $localLock) {
                $add('storage.shared_lock', 'fail', 'Set statamic.mcp.oauth.lock_store to a shared lock backend for multi-node OAuth mutations; a local cache lock is insufficient.');
            }
            $locks = (is_string($name) || $name === null) && Cache::store($name)->getStore() instanceof LockProvider;
            $add('storage.lock_contract', $locks ? 'pass' : 'fail', $locks
                ? 'The configured cache driver declares atomic-lock support; reachability was not tested.' : 'Set statamic.mcp.oauth.lock_store to a cache store with atomic-lock support.');
        } catch (\Throwable) {
            $add('storage.lock_contract', 'fail', 'Check statamic.mcp.oauth.lock_store and its cache.stores entry: the lock store could not be resolved. Details are omitted to protect credentials.');
        }
        try {
            $counts = ['full_access' => 0, 'replaced' => 0, 'inert' => 0, 'publish_delete' => 0];
            $maxExpiry = TokenService::maxExpiry();
            $overCap = 0;
            foreach (app(TokenStore::class)->listAll() as $token) {
                foreach (array_keys(TokenScope::v3Changes($token->scopes)) as $change) {
                    $counts[$change]++;
                }
                if ($maxExpiry !== null && ! $token->isExpired() && ($token->expiresAt === null || $token->expiresAt->greaterThan($maxExpiry))) {
                    $overCap++;
                }
            }
            if ($maxExpiry !== null) {
                $add('tokens.lifetime_cap', $overCap === 0 ? 'pass' : 'warn', $overCap === 0
                    ? 'Every token expires within security.max_token_lifetime_days.'
                    : "{$overCap} token(s) were issued before the lifetime cap and outlive it (security.max_token_lifetime_days). Give them an earlier expiry in Tools → MCP, or revoke them and create new ones.");
            }
            [$legacyFull, $replaced, $narrowed] = [$counts['full_access'], $counts['replaced'], $counts['publish_delete']];
            $add('migration.full_access', $legacyFull === 0 ? 'pass' : 'warn', $legacyFull === 0
                ? 'No token carries the v3 full-access scope.'
                : "{$legacyFull} token(s) carry the v3 full-access scope (*), which v4 refuses. Their owners must create a new token or authorize the client again; php artisan mcp:migrate-v4 revokes them.");
            $add('migration.admin_scopes', $replaced === 0 ? 'pass' : 'warn', $replaced === 0
                ? 'No token carries a v3 admin write scope.'
                : "{$replaced} token(s) carry structures:write, blueprints:write, users:write or system:write, which v4 replaced with :admin scopes. Run php artisan mcp:migrate-v4 to review them.");
            $add('migration.inert_scopes', $counts['inert'] === 0 ? 'pass' : 'warn', $counts['inert'] === 0
                ? 'No token carries content:read or content:write.'
                : "{$counts['inert']} token(s) carry content:read or content:write, which never granted anything. php artisan mcp:migrate-v4 --apply drops them from personal tokens; an OAuth client's next refresh drops them from its grant.");
            $add('migration.publish_delete', $narrowed === 0 ? 'pass' : 'warn', $narrowed === 0
                ? 'No write token lost publish or delete access in v4.'
                : "{$narrowed} token(s) can still edit but no longer publish or delete: v4 grants those through entries:publish, entries:delete, terms:delete and assets:delete. Before downgrading to v3, run php artisan mcp:migrate-v4 --rollback.");
        } catch (\Throwable) {
            $add('migration.full_access', 'fail', 'Check statamic.mcp.stores.tokens and statamic.mcp.storage: the token store could not be read. Details are omitted to protect credentials.');
        }
        if (ConfirmationActionGate::enabled()) {
            $elevated = (bool) config('statamic.users.elevated_sessions_enabled', true);
            $add('approvals.elevated_session', $elevated ? 'pass' : 'warn', $elevated
                ? 'Approving an MCP call needs a fresh re-authentication (Statamic elevated session).'
                : 'Statamic elevated sessions are off (STATAMIC_ELEVATED_SESSIONS_ENABLED=false), so an approval needs only a signed-in Control Panel session, and a browser agent driving that session could approve its own calls. Turn them back on.');
        }
        $notes = [
            'No write, network or lock probe was performed. Verify ingress, shared storage, retention and crash recovery separately; green configuration is not end-to-end proof.',
            'This command does not migrate anything. php artisan mcp:migrate-v4 plans token changes; --apply carries them out.',
        ];
        $failed = count(array_filter($checks, static fn (array $check): bool => $check['status'] === 'fail'));
        if ($this->option('json')) {
            $this->line(json_encode(['schema' => 1, 'read_only' => true, 'ok' => $failed === 0, 'checks' => $checks, 'notes' => $notes], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
        } else {
            $this->table(['Check', 'Status', 'Action'], array_map(static fn (array $check): array => array_values($check), $checks));
            $this->line('Configuration preflight only. A passing report does not certify release readiness or end-to-end security.');
            foreach ($notes as $note) {
                $this->line($note);
            }
        }

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }

    /**
     * The log channels the server writes to (the default and the deprecations
     * channel, stacks expanded) whose stream is stdout. Over stdio, stdout
     * carries JSON-RPC alone; one log line there breaks the client's parser.
     * Channel names are config keys, not values, so they are safe to print.
     *
     * @return list<string>
     */
    private static function stdoutLogChannels(): array
    {
        $pending = [config('logging.default'), config('logging.deprecations.channel')];
        $seen = [];
        $found = [];
        for ($i = 0; $i < count($pending); $i++) {
            $name = $pending[$i];
            if (! is_string($name) || isset($seen[$name])) {
                continue;
            }
            $seen[$name] = true;
            $channel = config("logging.channels.{$name}");
            if (! is_array($channel)) {
                continue;
            }
            if (($channel['driver'] ?? null) === 'stack' && is_array($channel['channels'] ?? null)) {
                array_push($pending, ...array_values($channel['channels']));

                continue;
            }
            $with = is_array($channel['handler_with'] ?? null) ? $channel['handler_with'] : (is_array($channel['with'] ?? null) ? $channel['with'] : []);
            foreach ([$channel['path'] ?? null, $with['stream'] ?? null] as $target) {
                if (is_string($target) && in_array(strtolower($target), ['php://stdout', 'php://output'], true)) {
                    $found[] = $name;
                    break;
                }
            }
        }

        return $found;
    }

    /**
     * The proxies Laravel's TrustProxies middleware trusts, as set with
     * `$middleware->trustProxies(at: ...)`. Laravel offers no getter.
     *
     * @return list<string>|string|null
     */
    private static function trustedProxies(): array|string|null
    {
        if (! property_exists(TrustProxies::class, 'alwaysTrustProxies')) {
            return null;
        }
        $value = (new \ReflectionProperty(TrustProxies::class, 'alwaysTrustProxies'))->getValue();

        if (is_array($value)) {
            return array_values(array_filter($value, is_string(...)));
        }

        return is_string($value) ? $value : null;
    }
}

<?php

use Cboxdk\StatamicMcp\OAuth\Drivers\BuiltInOAuthDriver;
use Cboxdk\StatamicMcp\Storage\Audit\FileAuditStore;
use Cboxdk\StatamicMcp\Storage\Tokens\FileTokenStore;

return [
    /*
    |--------------------------------------------------------------------------
    | Statamic MCP Server v2.0 Configuration
    |--------------------------------------------------------------------------
    |
    | Configure the Statamic MCP server for CLI and web access,
    | authentication, dashboard, and per-domain tool settings.
    |
    */

    /*
    |--------------------------------------------------------------------------
    | Web MCP Configuration
    |--------------------------------------------------------------------------
    |
    | Configure web-accessible MCP endpoints with authentication and routing.
    |
    */
    'web' => [
        'enabled' => env('STATAMIC_MCP_WEB_ENABLED', false),
        'path' => env('STATAMIC_MCP_WEB_PATH', '/mcp/statamic'),

        // Reject plain HTTP requests to the MCP endpoint (skipped in local/testing)
        'require_https' => env('STATAMIC_MCP_WEB_REQUIRE_HTTPS', true),

        // Allowed CORS origins for browser-based MCP clients.
        // Use ['*'] to allow all origins, or specify domains: ['https://example.com']
        // Leave empty to disable CORS headers (desktop MCP clients don't need them).
        'allowed_origins' => [],

        'middleware' => [
            'throttle:60,1',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Storage Drivers
    |--------------------------------------------------------------------------
    |
    | Configure which storage drivers to use for tokens and audit logs.
    | Swap to database drivers for multi-server / HA deployments.
    |
    | Supported: FileTokenStore (default), DatabaseTokenStore
    |            FileAuditStore (default), DatabaseAuditStore
    |
    */
    'stores' => [
        'tokens' => FileTokenStore::class,
        'audit' => FileAuditStore::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Storage Paths
    |--------------------------------------------------------------------------
    |
    | File paths used by the file-based storage drivers.
    |
    */
    'storage' => [
        'tokens_path' => storage_path('statamic-mcp/tokens'),
        'audit_path' => storage_path('statamic-mcp/audit.log'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Authentication Configuration
    |--------------------------------------------------------------------------
    |
    | Configure how MCP clients authenticate. Supports scoped API tokens
    | that limit access to specific tools and actions.
    |
    */
    'auth' => [
        // Guard name is 'mcp', registered by AuthServiceProvider
    ],

    /*
    |--------------------------------------------------------------------------
    | Dashboard Configuration
    |--------------------------------------------------------------------------
    |
    | Configure the Control Panel dashboard for managing MCP tokens,
    | monitoring usage, and generating client configurations.
    |
    */
    'dashboard' => [
        'enabled' => env('STATAMIC_MCP_DASHBOARD_ENABLED', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Security Configuration
    |--------------------------------------------------------------------------
    |
    | Configure security settings for MCP server access and permissions.
    |
    */
    'security' => [
        // Force web authentication mode even for CLI context
        'force_web_mode' => env('STATAMIC_MCP_FORCE_WEB_MODE', false),

        // Audit logging for all MCP operations
        'audit_logging' => env('STATAMIC_MCP_AUDIT_LOGGING', true),

        // @deprecated Use stores.audit and storage.audit_path instead.
        // Log channel name for MCP audit entries
        'audit_channel' => env('STATAMIC_MCP_AUDIT_CHANNEL', 'mcp'),

        // @deprecated Use stores.audit and storage.audit_path instead.
        // Path for the MCP audit log file
        'audit_path' => storage_path('logs/mcp-audit.log'),

        // Maximum upload size in bytes (default: 10MB)
        'max_upload_size' => env('STATAMIC_MCP_MAX_UPLOAD_SIZE', 10 * 1024 * 1024),

        // Include version information in API responses (enable for debugging, disable in production)
        'expose_versions' => env('STATAMIC_MCP_EXPOSE_VERSIONS', false),

        // Maximum token lifetime in days (null = unlimited)
        'max_token_lifetime_days' => env('STATAMIC_MCP_MAX_TOKEN_LIFETIME', 365),

        'tool_timeout_seconds' => env('STATAMIC_MCP_TOOL_TIMEOUT', 30),
    ],

    /*
    |--------------------------------------------------------------------------
    | Rate Limiting
    |--------------------------------------------------------------------------
    |
    | Global rate limiting for MCP tool calls. Applied per-token for web
    | requests and skipped for CLI context.
    |
    */
    'rate_limit' => [
        'max_attempts' => env('STATAMIC_MCP_RATE_LIMIT_MAX', 60),
        'decay_minutes' => env('STATAMIC_MCP_RATE_LIMIT_DECAY', 1),
    ],

    /*
    |--------------------------------------------------------------------------
    | Tool Configuration
    |--------------------------------------------------------------------------
    |
    | Per-domain tool settings. Each domain router can be individually
    | configured for web access and audit logging.
    |
    */
    /*
    |--------------------------------------------------------------------------
    | OAuth Configuration
    |--------------------------------------------------------------------------
    |
    | Configure the OAuth 2.1 authorization server for MCP client registration
    | and token exchange using PKCE (RFC 7636).
    |
    */
    'oauth' => [
        'enabled' => env('STATAMIC_MCP_OAUTH_ENABLED', true),
        'driver' => env('STATAMIC_MCP_OAUTH_DRIVER', BuiltInOAuthDriver::class),
        'code_ttl' => (int) env('STATAMIC_MCP_OAUTH_CODE_TTL', 600),
        'client_ttl' => (int) env('STATAMIC_MCP_OAUTH_CLIENT_TTL', 2592000),
        'token_ttl' => (int) env('STATAMIC_MCP_OAUTH_TOKEN_TTL', 604800), // 7 days
        'refresh_token_ttl' => (int) env('STATAMIC_MCP_OAUTH_REFRESH_TOKEN_TTL', 2592000), // 30 days
        'default_scopes' => array_filter(explode(',', env('STATAMIC_MCP_OAUTH_DEFAULT_SCOPES', '*'))),
        'max_clients' => (int) env('STATAMIC_MCP_OAUTH_MAX_CLIENTS', 1000),
    ],

    /*
    |--------------------------------------------------------------------------
    | Tool Configuration
    |--------------------------------------------------------------------------
    |
    | Per-domain tool settings. Each domain router can be individually
    | configured for web access and audit logging.
    |
    */
    'tools' => [
        'structures' => [
            'enabled' => true,
        ],
        'assets' => [
            'enabled' => true,
        ],
        'users' => [
            'enabled' => true,
        ],
        'system' => [
            'enabled' => true,
        ],
        'blueprints' => [
            'enabled' => true,
        ],
        'entries' => [
            'enabled' => true,
        ],
        'terms' => [
            'enabled' => true,
        ],
        'globals' => [
            'enabled' => true,
        ],
        'content-facade' => [
            'enabled' => true,
        ],
    ],

];

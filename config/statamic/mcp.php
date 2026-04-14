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
        'enabled' => env('STATAMIC_MCP_WEB_ENABLED', true),
        'path' => env('STATAMIC_MCP_WEB_PATH', '/mcp/statamic'),

        // Reject plain HTTP requests to the MCP endpoint (skipped in local/testing)
        'require_https' => env('STATAMIC_MCP_WEB_REQUIRE_HTTPS', true),

        // Allowed CORS origins for browser-based MCP clients.
        // Use ['*'] to allow all origins, or specify domains: ['https://example.com']
        // Leave empty to disable CORS headers (desktop MCP clients don't need them).
        'allowed_origins' => [],
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
        'oauth_clients_path' => storage_path('statamic-mcp/oauth/clients'),
        'oauth_codes_path' => storage_path('statamic-mcp/oauth/codes'),
        'oauth_refresh_path' => storage_path('statamic-mcp/oauth/refresh'),
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
    ],

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
        'default_scopes' => array_filter(explode(',', env('STATAMIC_MCP_OAUTH_DEFAULT_SCOPES', 'content:read,blueprints:read,structures:read,entries:read,terms:read,globals:read,assets:read,system:read,content-facade:read'))),
        'max_clients' => (int) env('STATAMIC_MCP_OAUTH_MAX_CLIENTS', 50),
        'max_clients_per_ip' => (int) env('STATAMIC_MCP_OAUTH_MAX_CLIENTS_PER_IP', 5),

        // Client ID Metadata Document (CIMD) support
        'cimd_enabled' => env('STATAMIC_MCP_OAUTH_CIMD_ENABLED', true),
        'cimd_fetch_timeout' => (int) env('STATAMIC_MCP_OAUTH_CIMD_FETCH_TIMEOUT', 5),
        'cimd_max_response_size' => (int) env('STATAMIC_MCP_OAUTH_CIMD_MAX_RESPONSE_SIZE', 5120),
        'cimd_cache_ttl' => (int) env('STATAMIC_MCP_OAUTH_CIMD_CACHE_TTL', 3600),
        'cimd_block_private_ips' => env('STATAMIC_MCP_OAUTH_CIMD_BLOCK_PRIVATE_IPS', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Tool Configuration
    |--------------------------------------------------------------------------
    |
    | Per-domain tool settings. Each tool can be toggled on/off via env vars.
    | Set STATAMIC_MCP_TOOL_{NAME}_ENABLED=false to disable a specific tool.
    |
    */
    'tools' => [
        'blueprints' => [
            'enabled' => env('STATAMIC_MCP_TOOL_BLUEPRINTS_ENABLED', true),
        ],
        'entries' => [
            'enabled' => env('STATAMIC_MCP_TOOL_ENTRIES_ENABLED', true),
        ],
        'terms' => [
            'enabled' => env('STATAMIC_MCP_TOOL_TERMS_ENABLED', true),
        ],
        'globals' => [
            'enabled' => env('STATAMIC_MCP_TOOL_GLOBALS_ENABLED', true),
        ],
        'structures' => [
            'enabled' => env('STATAMIC_MCP_TOOL_STRUCTURES_ENABLED', true),
        ],
        'assets' => [
            'enabled' => env('STATAMIC_MCP_TOOL_ASSETS_ENABLED', true),
        ],
        'users' => [
            'enabled' => env('STATAMIC_MCP_TOOL_USERS_ENABLED', true),
        ],
        'system' => [
            'enabled' => env('STATAMIC_MCP_TOOL_SYSTEM_ENABLED', true),
        ],
        'content-facade' => [
            'enabled' => env('STATAMIC_MCP_TOOL_CONTENT_FACADE_ENABLED', true),
        ],
    ],

];

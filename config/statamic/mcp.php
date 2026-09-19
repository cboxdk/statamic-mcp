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

        // Largest tool response, in bytes, before it is refused as too large.
        // The limit exists to protect the client's context window, so the right
        // value depends on the client; 0 disables the guard entirely.
        'max_response_size' => (int) env('STATAMIC_MCP_MAX_RESPONSE_SIZE', 100000),

        // Reject writes carrying keys that are not field handles inside a
        // replicator set, grid row, bard set or group. Statamic stores them as
        // inert data no template reads, so an invented handle otherwise reports
        // success while producing content that does not match. Not applied at
        // the top level of a record, where non-blueprint keys such as template,
        // layout and parent are legitimate.
        'reject_unknown_fields' => env('STATAMIC_MCP_REJECT_UNKNOWN_FIELDS', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Confirmation Tokens
    |--------------------------------------------------------------------------
    |
    | Require a two-step confirmation flow for destructive operations.
    | Uses stateless HMAC-SHA256 tokens bound to the exact operation.
    |
    | When enabled is null (default), confirmation is auto-detected:
    | enabled in production, disabled in local/development/testing/staging.
    | Set to true/false to override.
    |
    */
    'confirmation' => [
        'enabled' => env('STATAMIC_MCP_CONFIRMATION_ENABLED', null),
        'ttl' => (int) env('STATAMIC_MCP_CONFIRMATION_TTL', 300),

        /*
        | Per-domain list of actions that require the confirmation-token flow.
        | Domains not listed fall back to 'default'. An empty array disables
        | the gate for that domain. The '*' wildcard gates every action.
        |
        | Defaults preserve historical behaviour: 'delete' everywhere plus
        | create/generate/update on blueprints, and destructive revision actions on
        | entries. Operators can widen the gate per domain — e.g. require
        | confirmation on entries.update — without forking the package.
        */
        'actions' => [
            'default' => ['delete'],
            'blueprints' => ['create', 'generate', 'update', 'delete'],
            'entries' => ['delete', 'restore_revision', 'publish_working_copy'],
            // 'entries' => ['create', 'update', 'delete', 'publish', 'unpublish', 'restore_revision', 'publish_working_copy'],
            // 'globals' => ['update'],
            // 'terms'   => ['create', 'update', 'delete'],
            // 'assets'  => ['upload', 'update', 'delete', 'move', 'copy'],
            // 'users'   => ['create', 'update', 'delete', 'assign-role'],
        ],
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
    | Resources
    |--------------------------------------------------------------------------
    |
    | The read-only surface: statamic://blueprints and friends.
    |
    | These have their own switch because they used to share the tools' one. A
    | site that keeps its content model in Git turns the blueprints *tool* off
    | precisely because it can create and delete blueprints — and that also
    | removed the only read-only way for an agent to learn a blueprint's
    | fields, while the server's own instructions tell it to read the blueprint
    | before every write (issue #54).
    |
    | 'require_statamic_permission' keeps the Statamic permission check on top
    | of the token scope and the resource-policy allowlist. Set it to false if
    | your editors hold no 'configure fields' permission and you would rather
    | let the token scope you minted decide who may read schema.
    |
    */
    'resources' => [
        'enabled' => env('STATAMIC_MCP_RESOURCES_ENABLED', true),
        'require_statamic_permission' => env('STATAMIC_MCP_RESOURCES_REQUIRE_PERMISSION', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Cache
    |--------------------------------------------------------------------------
    |
    | Whether a write should clear Statamic's caches afterwards. The two are
    | separated because only one of them did harm.
    |
    | Stache clearing is OFF. Statamic's save() already updates the store and
    | its indexes, which is why a Control Panel save clears nothing. Clearing
    | ran `statamic:stache:clear` through Artisan inside the request, resetting
    | the in-memory stores mid-call; on a live multisite that left a structured
    | collection's tree empty, which Statamic then padded with every entry at
    | root — nested URLs flattened and a random entry became the homepage
    | (issue #53).
    |
    | Static clearing is ON. StaticCaching\Invalidate does subscribe to the
    | saved events, but its coverage has holes: it ignores CollectionSaved and
    | TaxonomySaved entirely, it listens for LocalizedTermSaved rather than the
    | TermSaved a new term dispatches, and on a slug change it invalidates the
    | new URL while the old one keeps serving its cached page. A stale page is
    | a bug your visitors see, and clearing the static cache is a rebuild
    | rather than corruption, so it stays on. Turn it off if you would rather
    | rely on your own invalidation rules.
    |
    | Both settings govern CONTENT writes only — entries, terms, global values,
    | assets. Structural writes (blueprints, collections, taxonomies,
    | navigations, global sets, users, roles, groups) always rebuild the Stache
    | and are not configurable, because Statamic does not rebuild the indexes
    | that depend on a schema or configuration change: a relationship field's
    | max_items, a collection's taxonomies, its mount, its dated flag, a
    | group's roles. Those writes are rare and deliberate; the frequent
    | content writes are the ones that caused #53. Deletes rebuild too: a
    | removal invalidates whatever pointed at the record, and Statamic does not
    | clean up term associations or orphaned virtual terms on its own.
    |
    */
    'cache' => [
        'clear_stache_after_write' => env('STATAMIC_MCP_CLEAR_STACHE_AFTER_WRITE', false),
        'clear_static_after_write' => env('STATAMIC_MCP_CLEAR_STATIC_AFTER_WRITE', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Tool Catalog
    |--------------------------------------------------------------------------
    |
    | How the tool list is presented to clients at connect time.
    |
    | With 'searchable' on, only the tools a session usually opens with are
    | listed — entries, blueprints and discovery. The rest are reached through
    | search_tools and execute_tools. The full catalog is ~31 KB of schema that
    | every client pays for on every connection before it has asked anything;
    | withholding the rest cuts that by about 60%. They stay fully available and
    | fully gated — only their schemas wait until a client asks for them.
    |
    | Turn it off if your MCP client handles search_tools poorly and you would
    | rather it saw every tool listed directly.
    |
    */
    'catalog' => [
        'searchable' => env('STATAMIC_MCP_SEARCHABLE_CATALOG', true),
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
            'resources' => [
                'read' => ['*'],
                'write' => ['*'],
            ],
            'denied_fields' => [],
        ],
        'entries' => [
            'enabled' => env('STATAMIC_MCP_TOOL_ENTRIES_ENABLED', true),
            'resources' => [
                'read' => ['*'],
                'write' => ['*'],
            ],
            'denied_fields' => [],
        ],
        'terms' => [
            'enabled' => env('STATAMIC_MCP_TOOL_TERMS_ENABLED', true),
            'resources' => [
                'read' => ['*'],
                'write' => ['*'],
            ],
            'denied_fields' => [],
        ],
        'globals' => [
            'enabled' => env('STATAMIC_MCP_TOOL_GLOBALS_ENABLED', true),
            'resources' => [
                'read' => ['*'],
                'write' => ['*'],
            ],
            'denied_fields' => [],
        ],
        'structures' => [
            'enabled' => env('STATAMIC_MCP_TOOL_STRUCTURES_ENABLED', true),
            'resources' => [
                'read' => ['*'],
                'write' => ['*'],
            ],
            'denied_fields' => [],
        ],
        'assets' => [
            'enabled' => env('STATAMIC_MCP_TOOL_ASSETS_ENABLED', true),
            'resources' => [
                'read' => ['*'],
                'write' => ['*'],
            ],
            'denied_fields' => [],
        ],
        'users' => [
            'enabled' => env('STATAMIC_MCP_TOOL_USERS_ENABLED', true),
            'resources' => [
                'read' => ['*'],
                'write' => ['*'],
            ],
            'denied_fields' => [],
        ],
        'system' => [
            'enabled' => env('STATAMIC_MCP_TOOL_SYSTEM_ENABLED', true),
        ],
        'content-facade' => [
            'enabled' => env('STATAMIC_MCP_TOOL_CONTENT_FACADE_ENABLED', true),
        ],
    ],

];

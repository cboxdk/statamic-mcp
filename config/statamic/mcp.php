<?php

use Cboxdk\StatamicMcp\Actions\Operations\FileOperationStore;
use Cboxdk\StatamicMcp\OAuth\Drivers\BuiltInOAuthDriver;
use Cboxdk\StatamicMcp\Storage\Audit\FileAuditStore;
use Cboxdk\StatamicMcp\Storage\Tokens\FileTokenStore;

return [

    // Registered v4 actions require explicit consent over either transport.
    'stdio' => ['token' => env('STATAMIC_MCP_STDIO_TOKEN')],
    // Explicit transport limits. Unknown/missing values disable execution.
    // Profiles restrict access; HTTP still requires a token and Statamic rights.
    'profiles' => [
        'http' => env('STATAMIC_MCP_HTTP_PROFILE'),
        'stdio' => env('STATAMIC_MCP_STDIO_PROFILE'),
    ],

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
        'enabled' => filter_var(env('STATAMIC_MCP_WEB_ENABLED', false), FILTER_VALIDATE_BOOLEAN),
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
        'operations' => FileOperationStore::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Storage Paths
    |--------------------------------------------------------------------------
    |
    | File paths used by the file-based storage drivers.
    |
    */
    'operations' => [
        'max_records' => 256,
        'retry_seconds' => 3600,
        // Shared deployments must select DatabaseOperationStore.
        'multi_node' => false,
    ],

    'storage' => [
        'operations_path' => storage_path('statamic-mcp/operations'),
        'tokens_path' => storage_path('statamic-mcp/tokens'),
        'audit_path' => storage_path('statamic-mcp/audit.log'),
        'oauth_clients_path' => storage_path('statamic-mcp/oauth/clients'),
        'oauth_codes_path' => storage_path('statamic-mcp/oauth/codes'),
        'oauth_refresh_path' => storage_path('statamic-mcp/oauth/refresh'),
        // Journals of mcp:migrate-v4 runs (scopes before/after, never secrets).
        'migrations_path' => storage_path('statamic-mcp/migrations'),
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
        // Includes base64 expansion for the default 10 MiB upload limit.
        'max_input_bytes' => 16 * 1024 * 1024,
        'max_input_values' => 20000,
        'max_input_depth' => 64,

        'max_upload_size' => (int) env('STATAMIC_MCP_MAX_UPLOAD_SIZE', 10 * 1024 * 1024),

        // statamic-assets upload can fetch the file from a URL (source_url),
        // for remote clients that cannot send megabytes of base64. The server
        // fetches it through OutboundFetcher: http(s) on default ports only,
        // public addresses only (checked on every redirect, connection pinned
        // to the checked address), at most max_upload_size bytes.
        // 'allowed_hosts' narrows it further: exact hosts or wildcards such as
        // '*.example.com'; empty allows any public host.
        // 'block_private_ips' => false is for local development only.
        'url_import' => [
            'enabled' => env('STATAMIC_MCP_URL_IMPORT_ENABLED', true),
            'timeout' => (int) env('STATAMIC_MCP_URL_IMPORT_TIMEOUT', 15),
            'allowed_hosts' => array_values(array_filter(explode(',', (string) env('STATAMIC_MCP_URL_IMPORT_ALLOWED_HOSTS', '')))),
            'block_private_ips' => env('STATAMIC_MCP_URL_IMPORT_BLOCK_PRIVATE_IPS', true),
        ],

        // Include framework versions in server/discover only (off in production).
        'expose_versions' => env('STATAMIC_MCP_EXPOSE_VERSIONS', false),

        // Maximum token lifetime in days (null = unlimited)
        'max_token_lifetime_days' => env('STATAMIC_MCP_MAX_TOKEN_LIFETIME', 365) === null ? null : (int) env('STATAMIC_MCP_MAX_TOKEN_LIFETIME', 365),

        'tool_timeout_seconds' => (int) env('STATAMIC_MCP_TOOL_TIMEOUT', 30),

        // Largest tool response, in bytes, before it is refused as too large.
        // The limit exists to protect the client's context window, so the right
        // value depends on the client; 0 disables the guard entirely.
        'max_response_size' => (int) env('STATAMIC_MCP_MAX_RESPONSE_SIZE', 100000),

        // Offer, and accept, only the scopes a user can exercise: the ones
        // whose actions their Statamic permissions allow, in a domain the
        // site has not closed (a tool switched off, or a resource allowlist
        // of [] under 'tools' below). Super admins get every open scope;
        // nobody else gets '*'. Caps the dashboard's scope picker, token
        // creation and the OAuth consent screen. The per-call checks run
        // regardless, so this is about not offering dead scopes rather than
        // about access.
        'scopes_follow_permissions' => env('STATAMIC_MCP_SCOPES_FOLLOW_PERMISSIONS', true),

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
    | Approvals
    |--------------------------------------------------------------------------
    |
    | Gated actions over HTTP wait for the person the call acts as to approve
    | them in the Control Panel (Tools → MCP → Approvals). An approval covers
    | one exact call, once, and expires after `ttl` seconds. Approving asks for
    | Statamic's elevated session. The local stdio server is not gated.
    |
    | When enabled is null (default), approvals are on in production only.
    | Set to true/false to override.
    |
    */
    'confirmation' => [
        'enabled' => env('STATAMIC_MCP_CONFIRMATION_ENABLED', null),
        'ttl' => (int) env('STATAMIC_MCP_CONFIRMATION_TTL', 900),

        /*
        | Per-domain list of actions that need a person's approval.
        | Domains not listed fall back to 'default'. An empty array disables
        | the gate for that domain. The '*' wildcard gates every action.
        |
        | Defaults: 'delete' everywhere, create/generate/update on blueprints,
        | destructive revision actions on entries, and every users action that
        | can hand an account to someone else. Operators can widen the gate per
        | domain — e.g. require approval on entries.update — without forking
        | the package. A config without a 'users' key still gates users
        | create, update, activate and assign_role; set 'users' => [] to stop.
        */
        'actions' => [
            'default' => ['delete'],
            'blueprints' => ['create', 'generate', 'update', 'delete', 'create_fieldset', 'update_fieldset', 'delete_fieldset'],
            'entries' => ['delete', 'restore_revision', 'publish_working_copy'],
            'forms' => ['delete_submission'],
            'users' => ['create', 'update', 'delete', 'activate', 'assign_role'],
            // 'entries' => ['create', 'update', 'delete', 'publish', 'unpublish', 'restore_revision', 'publish_working_copy'],
            // 'globals' => ['update'],
            // 'terms'   => ['create', 'update', 'delete'],
            // 'assets'  => ['upload', 'update', 'delete', 'move', 'copy'],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Approval channels
    |--------------------------------------------------------------------------
    |
    | Where a waiting approval is announced, besides the Approvals page.
    | 'auto' (default) picks what this site has:
    |   - Cbox Relay (cboxrelay.com) when cboxdk/laravel-relay-client is
    |     configured: mail, web push to the person's browsers and a webhook
    |     event (statamic_mcp.approval.requested) for Slack, Teams, n8n…
    |   - otherwise the site's own mailer.
    |   - Cbox ID (cboxid.com) when cboxdk/laravel-id-client is configured:
    |     the person approves on their phone (OpenID CIBA). Needs a client an
    |     operator registered at Cbox ID.
    | Or list them: 'mail', 'relay', 'cbox_id', or the class name of your own
    | ApprovalNotifier / ApprovalAuthority. 'none' turns them all off.
    |
    | Notifiers only tell; they link to the Approvals page and never approve.
    |
    */
    'approvals' => [
        'channels' => env('STATAMIC_MCP_APPROVAL_CHANNELS', 'auto'),

        // Mention Cbox Relay and Cbox ID in mails sent without them, and on
        // the Approvals page.
        'promote_cbox' => (bool) env('STATAMIC_MCP_PROMOTE_CBOX', true),

        'relay' => [
            'mail' => (bool) env('STATAMIC_MCP_APPROVAL_RELAY_MAIL', true),
            'push' => (bool) env('STATAMIC_MCP_APPROVAL_RELAY_PUSH', true),
            'webhook' => (bool) env('STATAMIC_MCP_APPROVAL_RELAY_WEBHOOK', true),
        ],

        'cbox_id' => [
            // The user field holding the person's Cbox ID user id (usr_…);
            // without it, their email is used (Cbox ID needs it confirmed).
            'user_field' => env('STATAMIC_MCP_APPROVAL_CBOX_ID_FIELD', 'cbox_id'),
            'scope' => env('STATAMIC_MCP_APPROVAL_CBOX_ID_SCOPE', 'openid'),
            'resource' => env('STATAMIC_MCP_APPROVAL_CBOX_ID_RESOURCE'),
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
        'max_attempts' => (int) env('STATAMIC_MCP_RATE_LIMIT_MAX', 60),
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
        // Must be shared across nodes; supports Laravel atomic locks.
        'lock_store' => env('STATAMIC_MCP_OAUTH_LOCK_STORE'),
        'enabled' => env('STATAMIC_MCP_OAUTH_ENABLED', true),
        'driver' => env('STATAMIC_MCP_OAUTH_DRIVER', BuiltInOAuthDriver::class),
        'code_ttl' => (int) env('STATAMIC_MCP_OAUTH_CODE_TTL', 600),
        'client_ttl' => (int) env('STATAMIC_MCP_OAUTH_CLIENT_TTL', 2592000),
        'token_ttl' => (int) env('STATAMIC_MCP_OAUTH_TOKEN_TTL', 604800), // 7 days
        'refresh_token_ttl' => (int) env('STATAMIC_MCP_OAUTH_REFRESH_TOKEN_TTL', 2592000), // 30 days
        'default_scopes' => array_filter(explode(',', env('STATAMIC_MCP_OAUTH_DEFAULT_SCOPES', 'blueprints:read,structures:read,entries:read,terms:read,globals:read,assets:read,system:read,content-facade:read'))),
        'max_clients' => (int) env('STATAMIC_MCP_OAUTH_MAX_CLIENTS', 50),
        // A registered client that has not been authorized within this many
        // seconds is removed and stops counting toward max_clients.
        'unused_client_ttl' => (int) env('STATAMIC_MCP_OAUTH_UNUSED_CLIENT_TTL', 86400),
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
    | of the token scope and the resource-policy allowlist, for the resources
    | AND for the read actions of the statamic-blueprints tool (list, get,
    | scan, types, validate). Set it to false if your editors hold no
    | 'configure' permission and you would rather let the token scope you
    | minted decide who may read schema. Writing a blueprint always needs
    | 'configure fields'.
    |
    | Not to be confused with the per-domain `tools.*.resources` allowlists
    | further down, which are the resource *policy* — which handles a token may
    | touch. This block is about the MCP resource surface itself.
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
    | What a write does to Statamic's caches once the tool call is finished.
    |
    | Statamic keeps the store of the item it saves current, but not indexes
    | elsewhere that depend on the write: a deleted entry's term usage, the
    | order of a moved page's siblings, the URIs of a collection mounted on a
    | deleted page, every index of a collection whose route, mount or
    | taxonomies changed, users' role membership when a group changes.
    |
    | stache_after_write:
    |   'repair' (default) — bring exactly those indexes up to date. The result
    |            equals a rebuild from disk (tests/Feature/Cache/
    |            StacheConsistencyTest) and a write costs what a Statamic save
    |            costs, not a rebuild of the whole site.
    |   'clear'  — clear the whole Stache, as 3.x did. About 1 ms per entry on
    |            the site, on every write.
    |   'none'   — leave the Stache alone and rely on your own invalidation.
    |
    | The static cache is flushed in full by default: listing pages, globals
    | and navigation render other records than the one written.
    |
    | Either runs after the call, never in the middle of it: a clear mid-call
    | emptied a live site's collection tree (issue #53).
    |
    */
    'cache' => [
        'stache_after_write' => env('STATAMIC_MCP_STACHE_AFTER_WRITE', 'repair'),
        // full: clear; statamic: native rules only; refresh: clear, then queue
        // previously cached pages. Null preserves the legacy switch below.
        'static_after_write' => env('STATAMIC_MCP_STATIC_AFTER_WRITE'),
        'clear_static_after_write' => env('STATAMIC_MCP_CLEAR_STATIC_AFTER_WRITE', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Tool Catalog
    |--------------------------------------------------------------------------
    |
    | How the tool list is presented to clients at connect time.
    |
    | Every tool is listed by default, each area as a -read tool and a -write
    | or -admin tool, and only the tools the caller's token can use. Claude
    | Code, Cursor and claude.ai search large catalogs themselves, and a listed
    | tool keeps its own name and annotations, which their permission rules use.
    |
    | Turn 'searchable' on for a client that loads every schema up front and
    | has no tool search of its own: only discovery, entries and blueprints are
    | listed, and the rest are reached through search_tools and execute_tools.
    | They stay fully gated, but a client then sees one execute_tools tool, so
    | it cannot tell reads from writes.
    |
    */
    'catalog' => [
        'searchable' => (bool) env('STATAMIC_MCP_SEARCHABLE_CATALOG', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Extra Tools
    |--------------------------------------------------------------------------
    |
    | Tools from other packages, served alongside the built-in ones. Each must
    | extend Cboxdk\StatamicMcp\Mcp\Tools\BaseRouter, so it is gated exactly like
    | them: the per-domain web toggle, token scope, Statamic permissions, resource
    | policy, confirmation gate and audit log. A tool whose domain has no token
    | scope here requires a full-access token.
    |
    | A class name is reached through search_tools; ['class' => …, 'searchable'
    | => false] lists it at connect time. Packages can instead call
    | StatamicMcpServer::registerTool(MyRouter::class) from their service provider.
    |
    */
    'extra_tools' => [
        // \Vendor\Package\Mcp\SiteHealthRouter::class,
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
        'forms' => [
            'enabled' => env('STATAMIC_MCP_TOOL_FORMS_ENABLED', true),
            'resources' => ['read' => ['*'], 'write' => ['*']],
            // Applies to definitions, submission reads and exports.
            'denied_fields' => [],
        ],
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

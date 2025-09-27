<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Statamic MCP Server Configuration
    |--------------------------------------------------------------------------
    |
    | This file configures the Statamic MCP server for enhanced development
    | experience with Antlers and Blade templates.
    |
    */

    /*
    |--------------------------------------------------------------------------
    | Tool Configuration
    |--------------------------------------------------------------------------
    |
    | Configure individual MCP tools and their permissions.
    |
    */
    'tools' => [
        'statamic' => [
            'content' => [
                'web_enabled' => env('STATAMIC_MCP_CONTENT_WEB_ENABLED', false),
                'audit_logging' => env('STATAMIC_MCP_CONTENT_AUDIT_LOGGING', true),
                'rate_limit' => [
                    'max_attempts' => env('STATAMIC_MCP_CONTENT_RATE_LIMIT_MAX', 60),
                    'decay_minutes' => env('STATAMIC_MCP_CONTENT_RATE_LIMIT_DECAY', 1),
                ],
            ],
            'structures' => [
                'web_enabled' => env('STATAMIC_MCP_STRUCTURES_WEB_ENABLED', false),
                'audit_logging' => env('STATAMIC_MCP_STRUCTURES_AUDIT_LOGGING', true),
                'rate_limit' => [
                    'max_attempts' => env('STATAMIC_MCP_STRUCTURES_RATE_LIMIT_MAX', 60),
                    'decay_minutes' => env('STATAMIC_MCP_STRUCTURES_RATE_LIMIT_DECAY', 1),
                ],
            ],
            'assets' => [
                'web_enabled' => env('STATAMIC_MCP_ASSETS_WEB_ENABLED', false),
                'audit_logging' => env('STATAMIC_MCP_ASSETS_AUDIT_LOGGING', true),
                'rate_limit' => [
                    'max_attempts' => env('STATAMIC_MCP_ASSETS_RATE_LIMIT_MAX', 60),
                    'decay_minutes' => env('STATAMIC_MCP_ASSETS_RATE_LIMIT_DECAY', 1),
                ],
            ],
            'users' => [
                'web_enabled' => env('STATAMIC_MCP_USERS_WEB_ENABLED', false),
                'audit_logging' => env('STATAMIC_MCP_USERS_AUDIT_LOGGING', true),
                'rate_limit' => [
                    'max_attempts' => env('STATAMIC_MCP_USERS_RATE_LIMIT_MAX', 60),
                    'decay_minutes' => env('STATAMIC_MCP_USERS_RATE_LIMIT_DECAY', 1),
                ],
            ],
            'system' => [
                'web_enabled' => env('STATAMIC_MCP_SYSTEM_WEB_ENABLED', false),
                'audit_logging' => env('STATAMIC_MCP_SYSTEM_AUDIT_LOGGING', true),
                'rate_limit' => [
                    'max_attempts' => env('STATAMIC_MCP_SYSTEM_RATE_LIMIT_MAX', 60),
                    'decay_minutes' => env('STATAMIC_MCP_SYSTEM_RATE_LIMIT_DECAY', 1),
                ],
            ],
            'blueprints' => [
                'web_enabled' => env('STATAMIC_MCP_BLUEPRINTS_WEB_ENABLED', false),
                'audit_logging' => env('STATAMIC_MCP_BLUEPRINTS_AUDIT_LOGGING', true),
                'rate_limit' => [
                    'max_attempts' => env('STATAMIC_MCP_BLUEPRINTS_RATE_LIMIT_MAX', 60),
                    'decay_minutes' => env('STATAMIC_MCP_BLUEPRINTS_RATE_LIMIT_DECAY', 1),
                ],
            ],
        ],
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
        'force_web_mode' => env('STATAMIC_MCP_FORCE_WEB_MODE', false),
    ],

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
        'middleware' => [
            \Cboxdk\StatamicMcp\Http\Middleware\AuthenticateForMcp::class,
            'throttle:60,1',
            \Cboxdk\StatamicMcp\Http\Middleware\RequireMcpPermission::class,
        ],
        'permissions' => [
            'required' => env('STATAMIC_MCP_WEB_REQUIRE_PERMISSION', true),
            'permission' => 'access mcp',
        ],
    ],

];

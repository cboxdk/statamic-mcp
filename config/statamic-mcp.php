<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Statamic MCP Secondary Configuration
    |--------------------------------------------------------------------------
    |
    | Additional configuration options for the Statamic MCP addon.
    | The main configuration is in config/statamic/mcp.php
    |
    */

    /*
    |--------------------------------------------------------------------------
    | Legacy Support
    |--------------------------------------------------------------------------
    |
    | Configuration for backwards compatibility and legacy features.
    |
    */
    'legacy' => [
        'enabled' => env('STATAMIC_MCP_LEGACY_ENABLED', false),
    ],
];

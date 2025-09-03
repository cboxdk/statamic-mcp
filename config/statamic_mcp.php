<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Statamic MCP Configuration
    |--------------------------------------------------------------------------
    |
    | This file configures the Statamic MCP server for enhanced development
    | experience with Antlers and Blade templates.
    |
    */

    /*
    |--------------------------------------------------------------------------
    | Primary Templating Language
    |--------------------------------------------------------------------------
    |
    | Set your project's primary templating language. This affects tool
    | suggestions and helps AI assistants provide contextually appropriate
    | template recommendations.
    |
    | Options: 'antlers', 'blade'
    |
    */
    'templating' => [
        'primary_language' => env('STATAMIC_MCP_PRIMARY_TEMPLATE', 'antlers'),
        'secondary_language' => env('STATAMIC_MCP_SECONDARY_TEMPLATE', 'blade'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Blueprint and Fieldset Paths
    |--------------------------------------------------------------------------
    |
    | Define the paths where blueprints and fieldsets are located.
    |
    */
    'paths' => [
        'blueprints' => resource_path('blueprints'),
        'fieldsets' => resource_path('fieldsets'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Blade Policy Configuration
    |--------------------------------------------------------------------------
    |
    | Configure the policy rules for Blade template linting and enforcement.
    |
    */
    'blade_policy' => [
        /*
        | Forbidden patterns in Blade templates
        */
        'forbid' => [
            'inline_php' => true,  // @php
            'facades' => ['Statamic', 'DB', 'Http', 'Cache', 'Storage'],
            'models_in_view' => true,  // Direct model access in views
        ],

        /*
        | Preferred approaches
        */
        'prefer' => [
            'tags' => true,  // Use Statamic tags
            'components' => true,  // Use Blade components
        ],

        /*
        | Allowed patterns
        */
        'allow' => [
            'pure_blade_logic' => true,  // @if, @foreach, etc. without side effects
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Cache Configuration
    |--------------------------------------------------------------------------
    |
    | Configure caching for blueprint schemas and parsed templates.
    |
    */
    'cache' => [
        'enabled' => env('STATAMIC_MCP_CACHE', true),
        'ttl' => 3600,  // Cache TTL in seconds
        'store' => env('STATAMIC_MCP_CACHE_STORE', 'file'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Rate Limiting
    |--------------------------------------------------------------------------
    |
    | Configure rate limiting for MCP tools to prevent abuse.
    |
    */
    'rate_limit' => [
        'enabled' => env('STATAMIC_MCP_RATE_LIMIT', true),
        'max_requests' => 60,  // Maximum requests
        'window' => 60,  // Window in seconds
    ],

    /*
    |--------------------------------------------------------------------------
    | Fixture Generation
    |--------------------------------------------------------------------------
    |
    | Configure fixture generation for testing.
    |
    */
    'fixtures' => [
        'locale' => 'en',
        'faker_locale' => 'en_US',
    ],

    /*
    |--------------------------------------------------------------------------
    | Debug Mode
    |--------------------------------------------------------------------------
    |
    | Enable debug mode for verbose logging and error reporting.
    |
    */
    'debug' => env('STATAMIC_MCP_DEBUG', false),
];

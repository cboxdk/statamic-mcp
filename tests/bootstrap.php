<?php

// PHPStan bootstrap file for better analysis

if (! function_exists('config')) {
    function config($key = null, $default = null)
    {
        return $default;
    }
}

if (! function_exists('app')) {
    function app($abstract = null, array $parameters = [])
    {
        return new stdClass;
    }
}

if (! function_exists('base_path')) {
    function base_path($path = '')
    {
        return '/path/to/base' . ($path ? '/' . $path : '');
    }
}

if (! function_exists('resource_path')) {
    function resource_path($path = '')
    {
        return '/path/to/resources' . ($path ? '/' . $path : '');
    }
}

if (! function_exists('storage_path')) {
    function storage_path($path = '')
    {
        return '/path/to/storage' . ($path ? '/' . $path : '');
    }
}

if (! function_exists('config_path')) {
    function config_path($path = '')
    {
        return '/path/to/config' . ($path ? '/' . $path : '');
    }
}

if (! function_exists('now')) {
    function now()
    {
        return new DateTime;
    }
}

// Mock Statamic classes for PHPStan
if (! class_exists('\Statamic\Statamic')) {
    class_alias('stdClass', '\Statamic\Statamic');
}

if (! class_exists('\Statamic\Facades\Entry')) {
    class_alias('stdClass', '\Statamic\Facades\Entry');
}

if (! class_exists('\Statamic\Facades\Collection')) {
    class_alias('stdClass', '\Statamic\Facades\Collection');
}

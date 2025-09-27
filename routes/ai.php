<?php

use Cboxdk\StatamicMcp\Mcp\Servers\StatamicMcpServer;

/*
|--------------------------------------------------------------------------
| MCP Server Registration
|--------------------------------------------------------------------------
|
| Here we register the Statamic MCP server for local development.
| The server will be available via the Artisan command:
| php artisan mcp:start statamic
|
*/

// Only register if MCP is available and not in testing
if (! app()->runningUnitTests() && class_exists('Laravel\Mcp\Facades\Mcp')) {
    try {
        // Capture any output during registration
        ob_start();
        \Laravel\Mcp\Facades\Mcp::local('statamic', StatamicMcpServer::class);
        $output = ob_get_clean();

        // Send any non-JSON output to stderr
        if (! empty(trim($output)) && ! str_contains($output, '"jsonrpc"')) {
            fwrite(STDERR, "MCP Registration output: $output\n");
        }
    } catch (\Exception $e) {
        fwrite(STDERR, 'MCP Registration error: ' . $e->getMessage() . "\n");
    }
}

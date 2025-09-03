<?php

use Cboxdk\StatamicMcp\Mcp\Servers\StatamicMcpServer;

/*
|--------------------------------------------------------------------------
| MCP Server Registration
|--------------------------------------------------------------------------
|
| Here we register the Statamic MCP server for local development.
| The server will be available via the Artisan command:
| php artisan mcp:serve statamic
|
*/

// Only register if MCP is available and not in testing
if (! app()->runningUnitTests() && class_exists('Laravel\Mcp\Server\Facades\Mcp')) {
    \Laravel\Mcp\Server\Facades\Mcp::local('statamic', StatamicMcpServer::class);
}

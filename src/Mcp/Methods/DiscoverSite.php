<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Mcp\Methods;

use Laravel\Mcp\Server\Methods\Discover;
use Laravel\Mcp\Server\ServerContext;
use Laravel\Mcp\Transport\JsonRpcRequest;
use Laravel\Mcp\Transport\JsonRpcResponse;
use Statamic\Statamic;

final class DiscoverSite extends Discover
{
    public function handle(JsonRpcRequest $request, ServerContext $context): JsonRpcResponse
    {
        $response = parent::handle($request, $context);
        if (config('statamic.mcp.security.expose_versions', false)) {
            $response->content = array_replace_recursive($response->content, [
                'result' => ['_meta' => ['statamic-mcp/versions' => [
                    'statamic' => Statamic::version(),
                    'laravel' => app()->version(),
                    'php' => PHP_VERSION,
                ]]],
            ]);
        }

        return $response;
    }
}

<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Http\Controllers\CP;

use Cboxdk\StatamicMcp\Services\ClientConfigGenerator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Statamic\Http\Controllers\CP\CpController;

class ConfigController extends CpController
{
    private ClientConfigGenerator $configGenerator;

    public function __construct(Request $request, ClientConfigGenerator $configGenerator)
    {
        parent::__construct($request);

        $this->configGenerator = $configGenerator;
    }

    /**
     * Generate a client configuration snippet for the specified MCP client type.
     */
    public function show(string $client): JsonResponse
    {
        $this->authorize('view mcp dashboard');

        $availableClients = $this->configGenerator->getAvailableClients();

        if (! isset($availableClients[$client])) {
            return response()->json([
                'message' => "Unknown client type: {$client}. Available clients: " . implode(', ', array_keys($availableClients)),
            ], 404);
        }

        /** @var string $baseUrl */
        $baseUrl = config('app.url', 'https://your-site.test');

        $clientInfo = $availableClients[$client];
        $method = $clientInfo['method'];

        /** @var array<string, mixed> $config */
        $config = $this->configGenerator->{$method}($baseUrl, '<YOUR_TOKEN>');

        $response = [
            'client' => $client,
            'name' => $clientInfo['name'],
            'description' => $clientInfo['description'],
            'config' => $config,
            'config_file' => $clientInfo['config_file'] ?? null,
        ];

        if (! empty($clientInfo['cli_method'])) {
            $cliMethod = $clientInfo['cli_method'];
            $response['cli'] = $this->configGenerator->{$cliMethod}($baseUrl, '<YOUR_TOKEN>');
        }

        return response()->json($response);
    }
}

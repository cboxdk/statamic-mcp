<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Http\Controllers\CP;

use Cboxdk\StatamicMcp\Services\ClientConfigGenerator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
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
                'message' => __('statamic-mcp::messages.unknown_client', ['client' => $client, 'available' => implode(', ', array_keys($availableClients))]),
            ], 404);
        }

        /** @var string $baseUrl */
        $baseUrl = config('app.url', 'https://your-site.test');

        $validated = request()->validate(['mode' => ['sometimes', 'string', Rule::in($availableClients[$client]['modes'])]]);
        /** @var string $mode */
        $mode = $validated['mode'] ?? $availableClients[$client]['modes'][0];

        return response()->json($this->configGenerator->snippet($client, $mode, $baseUrl));
    }
}

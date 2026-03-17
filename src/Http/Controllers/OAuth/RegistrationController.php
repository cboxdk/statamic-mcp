<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Http\Controllers\OAuth;

use Cboxdk\StatamicMcp\OAuth\Contracts\OAuthDriver;
use Cboxdk\StatamicMcp\OAuth\Exceptions\OAuthException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class RegistrationController extends Controller
{
    public function __construct(
        private readonly OAuthDriver $driver,
    ) {}

    /**
     * POST /mcp/oauth/register
     *
     * Dynamic Client Registration (RFC 7591).
     */
    public function store(Request $request): JsonResponse
    {
        /** @var mixed $clientName */
        $clientName = $request->input('client_name');

        if (! is_string($clientName) || trim($clientName) === '') {
            return response()->json([
                'error' => 'invalid_client_metadata',
                'error_description' => 'The client_name field is required.',
            ], 400);
        }

        if (mb_strlen(trim($clientName)) > 255) {
            return response()->json([
                'error' => 'invalid_client_metadata',
                'error_description' => 'The client_name must not exceed 255 characters.',
            ], 400);
        }

        /** @var mixed $redirectUris */
        $redirectUris = $request->input('redirect_uris');

        if (! is_array($redirectUris) || $redirectUris === []) {
            return response()->json([
                'error' => 'invalid_client_metadata',
                'error_description' => 'The redirect_uris field is required and must be a non-empty array.',
            ], 400);
        }

        // Validate each URI is a string
        foreach ($redirectUris as $uri) {
            if (! is_string($uri)) {
                return response()->json([
                    'error' => 'invalid_client_metadata',
                    'error_description' => 'Each redirect_uri must be a string.',
                ], 400);
            }
        }

        /** @var array<int, string> $redirectUris */
        try {
            $client = $this->driver->registerClient(trim($clientName), $redirectUris);
        } catch (OAuthException $e) {
            return response()->json($e->toOAuthResponse(), $e->getCode() ?: 400);
        }

        return response()->json([
            'client_id' => $client->clientId,
            'client_name' => $client->clientName,
            'redirect_uris' => $client->redirectUris,
            'client_id_issued_at' => $client->createdAt->getTimestamp(),
        ], 201);
    }
}

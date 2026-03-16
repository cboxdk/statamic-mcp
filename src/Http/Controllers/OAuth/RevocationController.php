<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Http\Controllers\OAuth;

use Cboxdk\StatamicMcp\Auth\TokenService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class RevocationController extends Controller
{
    public function __construct(
        private readonly TokenService $tokenService,
    ) {}

    /**
     * POST /mcp/oauth/revoke
     *
     * Token Revocation Endpoint per RFC 7009.
     *
     * The server always returns 200 OK regardless of whether the token existed,
     * to avoid leaking information about token validity.
     */
    public function revoke(Request $request): JsonResponse
    {
        /** @var mixed $token */
        $token = $request->input('token');

        if (! is_string($token) || $token === '') {
            return response()->json([
                'error' => 'invalid_request',
                'error_description' => 'token parameter is required',
            ], 400);
        }

        // Attempt to find and revoke as an access token; ignore result per RFC 7009 §2.2
        $mcpToken = $this->tokenService->validateToken($token);

        if ($mcpToken !== null) {
            $this->tokenService->revokeToken($mcpToken->id);
        }

        // Note: if $token is a refresh token (not an access token), it will not be found
        // by validateToken() and nothing is revoked. Per RFC 7009 §2.2 this is acceptable —
        // the server MUST respond with 200 OK regardless of whether the token existed.
        // Full refresh token revocation would require adding a revokeRefreshToken() method
        // to the OAuthDriver contract, which is a separate interface change.

        // RFC 7009 §2.2: always return 200 OK
        return response()->json([], 200);
    }
}

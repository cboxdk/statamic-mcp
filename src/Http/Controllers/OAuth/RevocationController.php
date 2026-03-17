<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Http\Controllers\OAuth;

use Cboxdk\StatamicMcp\Auth\TokenService;
use Cboxdk\StatamicMcp\OAuth\Contracts\OAuthDriver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class RevocationController extends Controller
{
    public function __construct(
        private readonly TokenService $tokenService,
        private readonly OAuthDriver $oauthDriver,
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

        // Try to revoke as an access token first
        $mcpToken = $this->tokenService->validateToken($token);

        if ($mcpToken !== null) {
            $this->tokenService->revokeToken($mcpToken->id);
        } else {
            // Not an access token — try as a refresh token
            $this->oauthDriver->revokeRefreshToken($token);
        }

        // RFC 7009 §2.2: always return 200 OK
        return response()->json([], 200);
    }
}

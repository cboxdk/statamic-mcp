<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Http\Controllers\OAuth;

use Carbon\Carbon;
use Cboxdk\StatamicMcp\Auth\TokenScope;
use Cboxdk\StatamicMcp\Auth\TokenService;
use Cboxdk\StatamicMcp\OAuth\Contracts\OAuthDriver;
use Cboxdk\StatamicMcp\OAuth\Exceptions\OAuthException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class OAuthTokenController extends Controller
{
    public function __construct(
        private readonly OAuthDriver $oauthDriver,
        private readonly TokenService $tokenService,
    ) {}

    /**
     * POST /mcp/oauth/token
     *
     * OAuth 2.1 Token Exchange (authorization_code and refresh_token grants).
     */
    public function store(Request $request): JsonResponse
    {
        /** @var mixed $grantType */
        $grantType = $request->input('grant_type');

        return match ($grantType) {
            'authorization_code' => $this->handleAuthCodeExchange($request),
            'refresh_token' => $this->handleRefreshTokenExchange($request),
            default => response()->json([
                'error' => 'unsupported_grant_type',
                'error_description' => 'Supported grant types: authorization_code, refresh_token.',
            ], 400),
        };
    }

    /**
     * Handle authorization_code grant type.
     */
    private function handleAuthCodeExchange(Request $request): JsonResponse
    {
        /** @var mixed $code */
        $code = $request->input('code');
        /** @var mixed $redirectUri */
        $redirectUri = $request->input('redirect_uri');
        /** @var mixed $clientId */
        $clientId = $request->input('client_id');
        /** @var mixed $codeVerifier */
        $codeVerifier = $request->input('code_verifier');

        if (! is_string($code) || $code === ''
            || ! is_string($redirectUri) || $redirectUri === ''
            || ! is_string($clientId) || $clientId === ''
            || ! is_string($codeVerifier) || $codeVerifier === ''
        ) {
            return response()->json([
                'error' => 'invalid_request',
                'error_description' => 'The code, redirect_uri, client_id, and code_verifier parameters are required.',
            ], 400);
        }

        try {
            $authCode = $this->oauthDriver->exchangeCode($code, $codeVerifier, $clientId, $redirectUri);
        } catch (OAuthException $e) {
            return response()->json($e->toOAuthResponse(), $e->httpStatus);
        }

        return $this->issueTokenResponse($clientId, $authCode->userId, $authCode->scopes);
    }

    /**
     * Handle refresh_token grant type.
     */
    private function handleRefreshTokenExchange(Request $request): JsonResponse
    {
        /** @var mixed $refreshToken */
        $refreshToken = $request->input('refresh_token');
        /** @var mixed $clientId */
        $clientId = $request->input('client_id');

        if (! is_string($refreshToken) || $refreshToken === ''
            || ! is_string($clientId) || $clientId === ''
        ) {
            return response()->json([
                'error' => 'invalid_request',
                'error_description' => 'The refresh_token and client_id parameters are required.',
            ], 400);
        }

        try {
            $authCode = $this->oauthDriver->exchangeRefreshToken($refreshToken, $clientId);
        } catch (OAuthException $e) {
            return response()->json($e->toOAuthResponse(), $e->httpStatus);
        }

        return $this->issueTokenResponse($clientId, $authCode->userId, $authCode->scopes);
    }

    /**
     * Issue an access token and refresh token, then build the JSON response.
     *
     * Shared by both authorization_code and refresh_token grant flows.
     *
     * @param  string  $clientId  The OAuth client ID.
     * @param  string  $userId  The authenticated user ID.
     * @param  array<int, string>  $scopes  The granted scope strings.
     */
    private function issueTokenResponse(string $clientId, string $userId, array $scopes): JsonResponse
    {
        $client = $this->oauthDriver->findClient($clientId);
        $clientName = $client !== null ? $client->clientName : 'OAuth Client';

        /** @var int $tokenTtl */
        $tokenTtl = config('statamic.mcp.oauth.token_ttl', 86400);
        $expiresAt = Carbon::now()->addSeconds($tokenTtl);

        $resolvedScopes = TokenScope::resolveMany($scopes);

        // Revoke previous access tokens for this client+user to prevent accumulation
        $this->tokenService->revokeOAuthTokens($userId, $clientId);

        $result = $this->tokenService->createToken(
            $userId,
            "{$clientName} (OAuth)",
            $resolvedScopes,
            $expiresAt,
            $clientId,
            $clientName,
        );

        $refreshToken = $this->oauthDriver->createRefreshToken(
            $userId,
            $clientId,
            $scopes,
        );

        return response()->json([
            'access_token' => $result['token'],
            'token_type' => 'Bearer',
            'expires_in' => $tokenTtl,
            'refresh_token' => $refreshToken,
            'scope' => implode(' ', $scopes),
        ]);
    }
}

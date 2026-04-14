<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Http\Controllers\OAuth;

use Cboxdk\StatamicMcp\Auth\TokenScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;

class DiscoveryController extends Controller
{
    /**
     * GET /.well-known/oauth-protected-resource
     *
     * Returns the OAuth Protected Resource Metadata (RFC 9728).
     */
    public function protectedResource(): JsonResponse
    {
        $baseUrl = $this->getBaseUrl();

        /** @var string $mcpPath */
        $mcpPath = config('statamic.mcp.web.path', '/mcp/statamic');

        $scopes = array_map(
            fn (TokenScope $scope): string => $scope->value,
            TokenScope::cases(),
        );

        return response()->json([
            'resource' => $baseUrl . $mcpPath,
            'authorization_servers' => [$baseUrl],
            'scopes_supported' => $scopes,
            'bearer_methods_supported' => ['header'],
        ]);
    }

    /**
     * GET /.well-known/oauth-authorization-server
     *
     * Returns the OAuth Authorization Server Metadata (RFC 8414).
     */
    public function authorizationServer(): JsonResponse
    {
        $baseUrl = $this->getBaseUrl();

        $scopes = array_map(
            fn (TokenScope $scope): string => $scope->value,
            TokenScope::cases(),
        );

        $metadata = [
            'issuer' => $baseUrl,
            'authorization_endpoint' => $baseUrl . '/' . trim((string) config('statamic.cp.route', 'cp'), '/') . '/mcp/oauth/authorize',
            'token_endpoint' => $baseUrl . '/mcp/oauth/token',
            'registration_endpoint' => $baseUrl . '/mcp/oauth/register',
            'revocation_endpoint' => $baseUrl . '/mcp/oauth/revoke',
            'scopes_supported' => $scopes,
            'response_types_supported' => ['code'],
            'grant_types_supported' => ['authorization_code', 'refresh_token'],
            'code_challenge_methods_supported' => ['S256'],
            'token_endpoint_auth_methods_supported' => ['none'],
        ];

        if (config('statamic.mcp.oauth.cimd_enabled') === true) {
            $metadata['client_id_metadata_document_supported'] = true;
        }

        return response()->json($metadata);
    }

    /**
     * Derive base URL from the current request, not from config.
     * This ensures ngrok/tunnels/proxies work without changing APP_URL.
     */
    private function getBaseUrl(): string
    {
        $scheme = request()->getScheme();
        $host = request()->getHost();
        $port = request()->getPort();

        $base = $scheme . '://' . $host;

        // Only append port if non-standard
        if (($scheme === 'https' && $port !== 443) || ($scheme === 'http' && $port !== 80)) {
            $base .= ':' . $port;
        }

        return $base;
    }
}

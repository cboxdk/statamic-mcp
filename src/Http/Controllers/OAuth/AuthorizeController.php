<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Http\Controllers\OAuth;

use Cboxdk\StatamicMcp\Auth\TokenScope;
use Cboxdk\StatamicMcp\OAuth\Cimd\CimdClientId;
use Cboxdk\StatamicMcp\OAuth\Cimd\CimdFetchException;
use Cboxdk\StatamicMcp\OAuth\Cimd\CimdResolver;
use Cboxdk\StatamicMcp\OAuth\Cimd\CimdValidationException;
use Cboxdk\StatamicMcp\OAuth\Contracts\OAuthDriver;
use Cboxdk\StatamicMcp\OAuth\Exceptions\OAuthException;
use Cboxdk\StatamicMcp\OAuth\OAuthClient;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Statamic\Facades\User;

class AuthorizeController extends Controller
{
    public function __construct(
        private readonly OAuthDriver $oauthDriver,
        private readonly CimdResolver $cimdResolver,
    ) {}

    /**
     * GET /mcp/oauth/authorize
     *
     * Display the OAuth consent screen for the user to approve/deny.
     */
    public function show(Request $request): View|RedirectResponse
    {
        // Check authentication — redirect to Statamic CP login if not logged in.
        // This route uses statamic.cp middleware (session, CSRF, guard) but NOT
        // statamic.cp.authenticated, so we handle auth here.
        //
        // We set url.intended so that after login, Statamic's authenticated() method
        // calls redirect()->intended() which redirects straight to this authorize URL.
        // This is reliable regardless of HTTP Referer headers — the server-side redirect
        // goes to our consent page (a Blade view, not Inertia), and Inertia automatically
        // does a full page visit when it encounters a non-Inertia response.
        $user = User::current();
        if (! $user) {
            /** @var string $cpRoute */
            $cpRoute = config('statamic.cp.route', 'cp');

            redirect()->setIntendedUrl($request->fullUrl());

            return redirect($cpRoute . '/auth/login');
        }

        // Validate client and redirect_uri FIRST — before any redirect-based error
        // responses. This prevents open redirect attacks where an attacker crafts a
        // URL with redirect_uri=https://evil.com and triggers a validation error that
        // redirects to the unvalidated URI.
        /** @var string $clientId */
        $clientId = $request->query('client_id', '');

        // findClient() may throw OAuthException for URL-format client_ids
        // (BuiltInOAuthDriver rejects non-filename identifiers). Treat the
        // exception as "not found" so the CIMD fallback can activate.
        try {
            $client = $this->oauthDriver->findClient($clientId);
        } catch (OAuthException) {
            $client = null;
        }

        if ($client === null) {
            $client = $this->resolveClientViaCimd($clientId);
        }

        if ($client === null) {
            /** @phpstan-ignore return.type (abort returns never but PHPStan doesn't know) */
            return abort(400, 'Unknown client_id.');
        }

        /** @var string $redirectUri */
        $redirectUri = $request->query('redirect_uri', '');

        if ($redirectUri === '' || ! in_array($redirectUri, $client->redirectUris, true)) {
            /** @phpstan-ignore return.type (abort returns never but PHPStan doesn't know) */
            return abort(400, 'Invalid redirect_uri.');
        }

        // Now safe to use redirect-based errors — redirect_uri is validated
        /** @var string $responseType */
        $responseType = $request->query('response_type', '');

        if ($responseType !== 'code') {
            return $this->redirectWithError(
                $request,
                'unsupported_response_type',
                'Only response_type=code is supported.',
                $redirectUri,
            );
        }

        /** @var string $codeChallenge */
        $codeChallenge = $request->query('code_challenge', '');

        if ($codeChallenge === '') {
            return $this->redirectWithError(
                $request,
                'invalid_request',
                'The code_challenge parameter is required.',
                $redirectUri,
            );
        }

        /** @var string $codeChallengeMethod */
        $codeChallengeMethod = $request->query('code_challenge_method', '');

        if ($codeChallengeMethod !== 'S256') {
            return $this->redirectWithError(
                $request,
                'invalid_request',
                'Only code_challenge_method=S256 is supported.',
                $redirectUri,
            );
        }

        /** @var string $scope */
        $scope = $request->query('scope', '');
        $validScopeValues = array_map(
            fn (TokenScope $s): string => $s->value,
            TokenScope::cases(),
        );

        /** @var array<int, array{value: string, label: string}> $requestedScopes */
        $requestedScopes = [];

        if ($scope !== '') {
            $scopeParts = explode(' ', $scope);

            foreach ($scopeParts as $scopeValue) {
                if (! in_array($scopeValue, $validScopeValues, true)) {
                    return $this->redirectWithError(
                        $request,
                        'invalid_scope',
                        "Unknown scope: {$scopeValue}",
                        $redirectUri,
                    );
                }

                $tokenScope = TokenScope::from($scopeValue);
                $requestedScopes[] = [
                    'value' => $tokenScope->value,
                    'label' => $tokenScope->label(),
                ];
            }
        }

        // If no scopes requested, default to configured default scopes (not all)
        if ($requestedScopes === []) {
            /** @var array<int, string> $defaultScopeValues */
            $defaultScopeValues = config('statamic.mcp.oauth.default_scopes', []);

            foreach ($defaultScopeValues as $scopeValue) {
                $tokenScope = TokenScope::tryFrom($scopeValue);
                if ($tokenScope !== null) {
                    $requestedScopes[] = [
                        'value' => $tokenScope->value,
                        'label' => $tokenScope->label(),
                    ];
                }
            }
        }

        // Resource parameter (RFC 8707) — validate if present using request URL, not config
        // This ensures proxies/tunnels (ngrok, cloudflare) work correctly
        /** @var string $resource */
        $resource = $request->query('resource', '');

        if ($resource !== '') {
            /** @var string $mcpPath */
            $mcpPath = config('statamic.mcp.web.path', '/mcp/statamic');
            $scheme = $request->getScheme();
            $host = $request->getHost();
            $expectedResource = $scheme . '://' . $host . $mcpPath;

            // Also accept config-based URL as fallback
            /** @var string $appUrl */
            $appUrl = config('app.url', 'http://localhost');
            $configResource = rtrim($appUrl, '/') . $mcpPath;

            if ($resource !== $expectedResource && $resource !== $configResource) {
                return $this->redirectWithError(
                    $request,
                    'invalid_request',
                    'Invalid resource parameter.',
                    $redirectUri,
                );
            }
        }

        /** @var string $state */
        $state = $request->query('state', '');

        /** @var array<int, string> $defaultScopes */
        $defaultScopes = config('statamic.mcp.oauth.default_scopes', []);

        /** @var view-string $viewName */
        $viewName = 'statamic-mcp::oauth.consent';

        return view($viewName, [
            'client' => $client,
            'scopes' => $requestedScopes,
            'defaultScopes' => $defaultScopes,
            'oauthParams' => [
                'client_id' => $clientId,
                'redirect_uri' => $redirectUri,
                'state' => $state,
                'code_challenge' => $codeChallenge,
                'code_challenge_method' => 'S256',
                'scope' => $scope,
            ],
        ]);
    }

    /**
     * POST /mcp/oauth/authorize
     *
     * Process the user's approval or denial of the OAuth authorization request.
     */
    public function approve(Request $request): RedirectResponse
    {
        /** @var string $clientId */
        $clientId = $request->input('client_id', '');

        /** @var string $redirectUri */
        $redirectUri = $request->input('redirect_uri', '');

        /** @var string $state */
        $state = $request->input('state', '');

        /** @var string $decision */
        $decision = $request->input('decision', '');

        // Validate client and redirect_uri BEFORE checking deny/approve
        // to prevent open redirect via the deny path
        try {
            $client = $this->oauthDriver->findClient($clientId);
        } catch (OAuthException) {
            $client = null;
        }

        if ($client === null) {
            $client = $this->resolveClientViaCimd($clientId);
        }

        if ($client === null) {
            /** @phpstan-ignore return.type (abort returns never but PHPStan doesn't know) */
            return abort(400, 'Unknown client_id.');
        }

        if (! in_array($redirectUri, $client->redirectUris, true)) {
            /** @phpstan-ignore return.type (abort returns never but PHPStan doesn't know) */
            return abort(400, 'Invalid redirect_uri.');
        }

        if ($decision !== 'approve') {
            return redirect($redirectUri . '?' . http_build_query(array_filter([
                'error' => 'access_denied',
                'state' => $state,
            ])));
        }

        $user = User::current();

        if ($user === null) {
            return redirect($redirectUri . '?' . http_build_query(array_filter([
                'error' => 'access_denied',
                'error_description' => 'No authenticated user.',
                'state' => $state,
            ])));
        }

        /** @var string $userId */
        $userId = $user->id();

        // Get originally requested scopes from the hidden form field (set by show())
        /** @var string $originalScope */
        $originalScope = is_string($request->input('scope', '')) ? $request->input('scope', '') : '';
        $allowedScopes = array_filter(explode(' ', $originalScope));

        if ($allowedScopes === []) {
            /** @var array<int, string> $defaultScopeValues */
            $defaultScopeValues = config('statamic.mcp.oauth.default_scopes', []);
            $allowedScopes = $defaultScopeValues;
        }

        // Get user-selected scopes from checkboxes
        /** @var mixed $rawScopes */
        $rawScopes = $request->input('scopes', []);
        $selectedScopes = is_array($rawScopes) ? array_values(array_filter($rawScopes, 'is_string')) : [];

        // Only allow scopes that were in the original request (prevent scope escalation)
        /** @var array<int, string> $scopes */
        $scopes = array_values(array_intersect($selectedScopes, $allowedScopes));
        if (empty($scopes)) {
            $scopes = $allowedScopes; // Default to all originally requested
        }

        /** @var string $codeChallenge */
        $codeChallenge = $request->input('code_challenge', '');

        /** @var string $codeChallengeMethod */
        $codeChallengeMethod = $request->input('code_challenge_method', 'S256');

        $code = $this->oauthDriver->createAuthCode(
            $clientId,
            $userId,
            $scopes,
            $codeChallenge,
            $codeChallengeMethod,
            $redirectUri,
        );

        return redirect($redirectUri . '?' . http_build_query(array_filter([
            'code' => $code,
            'state' => $state,
        ])));
    }

    /**
     * Attempt to resolve a client_id via CIMD (Client ID Metadata Document).
     *
     * Returns null if CIMD is disabled, the client_id is not a valid CIMD URL,
     * or if fetching/validation fails.
     */
    private function resolveClientViaCimd(string $clientId): ?OAuthClient
    {
        $cimdClientId = CimdClientId::tryFrom($clientId);

        if ($cimdClientId === null) {
            return null;
        }

        if (config('statamic.mcp.oauth.cimd_enabled') !== true) {
            abort(400, 'CIMD client_id resolution is disabled.');
        }

        try {
            $metadata = $this->cimdResolver->resolve($cimdClientId);
        } catch (CimdFetchException $e) {
            abort(400, 'Failed to fetch CIMD metadata: ' . $e->getMessage());
        } catch (CimdValidationException $e) {
            abort(400, 'Invalid CIMD metadata: ' . $e->getMessage());
        }

        return OAuthClient::fromCimdMetadata($metadata);
    }

    /**
     * Build a redirect response with an OAuth error.
     */
    private function redirectWithError(
        Request $request,
        string $error,
        string $description,
        ?string $redirectUri = null,
    ): RedirectResponse {
        $uri = $redirectUri;

        if ($uri === null || $uri === '') {
            /** @var string $fallback */
            $fallback = $request->query('redirect_uri', '');
            $uri = $fallback;
        }

        // If we still have no redirect URI, redirect back
        if ($uri === '') {
            return redirect()->back()->withErrors(['error' => $error, 'error_description' => $description]);
        }

        return redirect($uri . '?' . http_build_query(array_filter([
            'error' => $error,
            'error_description' => $description,
            'state' => $request->query('state', ''),
        ])));
    }
}

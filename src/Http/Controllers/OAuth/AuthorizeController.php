<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Http\Controllers\OAuth;

use Cboxdk\StatamicMcp\Actions\CapabilityRegistry;
use Cboxdk\StatamicMcp\Actions\CapabilitySnapshot;
use Cboxdk\StatamicMcp\Auth\ScopeAvailability;
use Cboxdk\StatamicMcp\Auth\TokenScope;
use Cboxdk\StatamicMcp\OAuth\Cimd\CimdClientId;
use Cboxdk\StatamicMcp\OAuth\Cimd\CimdFetchException;
use Cboxdk\StatamicMcp\OAuth\Cimd\CimdResolver;
use Cboxdk\StatamicMcp\OAuth\Cimd\CimdValidationException;
use Cboxdk\StatamicMcp\OAuth\ConsentOffer;
use Cboxdk\StatamicMcp\OAuth\Contracts\OAuthDriver;
use Cboxdk\StatamicMcp\OAuth\Exceptions\OAuthException;
use Cboxdk\StatamicMcp\OAuth\OAuthClient;
use Cboxdk\StatamicMcp\OAuth\RedirectUriMatcher;
use Cboxdk\StatamicMcp\OAuth\ResourceIndicator;
use Cboxdk\StatamicMcp\Support\UserIds;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Statamic\Facades\User;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

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
    public function show(Request $request): HttpResponse|View
    {
        // Check authentication — redirect to Statamic CP login if not logged in.
        // This route uses statamic.cp middleware (session, CSRF, guard) but NOT
        // statamic.cp.authenticated, so we handle auth here.
        //
        // We set url.intended so that after login, Statamic's authenticated() method
        // calls redirect()->intended() which redirects straight to this authorize URL.
        // This is reliable regardless of HTTP Referer headers. Consent uses the
        // native CP components, but submits a normal form for the OAuth redirect.
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
            return $this->errorPage('invalid_client', __('statamic-mcp::messages.oauth.unregistered_client'));
        }

        /** @var string $redirectUri */
        $redirectUri = $request->query('redirect_uri', '');

        if (! RedirectUriMatcher::matches($redirectUri, $client->redirectUris)) {
            return $this->errorPage('invalid_request', __('statamic-mcp::messages.oauth.unregistered_redirect'));
        }

        // Nothing is sent to redirect_uri before the person has seen the
        // consent screen. Registration is anonymous, so a client can name any
        // address; redirecting an error there straight away would make this
        // site an open redirect (RFC 9700 §4.11.2).
        /** @var string $responseType */
        $responseType = $request->query('response_type', '');

        if ($responseType !== 'code') {
            return $this->errorPage(
                'unsupported_response_type',
                __('statamic-mcp::messages.oauth.unsupported_response_type'),
            );
        }

        /** @var string $codeChallenge */
        $codeChallenge = $request->query('code_challenge', '');

        if ($codeChallenge === '') {
            return $this->errorPage(
                'invalid_request',
                __('statamic-mcp::messages.oauth.challenge_required'),
            );
        }

        /** @var string $codeChallengeMethod */
        $codeChallengeMethod = $request->query('code_challenge_method', '');

        if ($codeChallengeMethod !== 'S256') {
            return $this->errorPage(
                'invalid_request',
                __('statamic-mcp::messages.oauth.unsupported_challenge_method'),
            );
        }

        /** @var string $scope */
        $scope = $request->query('scope', '');
        $availability = app(ScopeAvailability::class);
        $validScopeValues = $availability->names();

        /** @var array<int, array{value: string, label: string}> $requestedScopes */
        $requestedScopes = [];

        if ($scope !== '') {
            $scopeParts = explode(' ', $scope);

            foreach ($scopeParts as $scopeValue) {
                if (! in_array($scopeValue, $validScopeValues, true)) {
                    return $this->errorPage(
                        'invalid_scope',
                        __('statamic-mcp::messages.oauth.unknown_scope', ['scope' => $scopeValue]),
                    );
                }

                $requestedScopes[] = ['value' => $scopeValue, 'label' => $availability->label($scopeValue)];
            }
        }

        // If no scopes requested, default to configured default scopes (not all)
        if ($requestedScopes === []) {
            /** @var array<int, string> $defaultScopeValues */
            $defaultScopeValues = config('statamic.mcp.oauth.default_scopes', []);

            foreach ($defaultScopeValues as $scopeValue) {
                if (in_array($scopeValue, $validScopeValues, true)) {
                    $requestedScopes[] = ['value' => $scopeValue, 'label' => $availability->label($scopeValue)];
                }
            }
        }

        // Offer only what this user can exercise. A client asking for more
        // than that is not an error — it asked for its full feature set, and
        // a `*` is spelled out as the user's own reach — but one asking for
        // nothing the user has gets told so.
        $availability = app(ScopeAvailability::class);
        $requestedFullAccess = in_array(TokenScope::FullAccess->value, array_column($requestedScopes, 'value'), true);
        $options = array_column($availability->options($user), null, 'value');
        $requestedScopes = array_map(
            fn (string $value): array => $options[$value],
            $availability->grantable($user, array_column($requestedScopes, 'value')),
        );

        if ($requestedScopes === []) {
            return $this->errorPage(
                'invalid_scope',
                __('statamic-mcp::messages.oauth.no_available_scopes'),
            );
        }

        try {
            ResourceIndicator::fromRequest($request);
        } catch (OAuthException $error) {
            return $this->errorPage($error->errorCode, $error->getMessage());
        }

        /** @var string $state */
        $state = $request->query('state', '');

        /** @var array<int, string> $configuredDefaults */
        $configuredDefaults = config('statamic.mcp.oauth.default_scopes', []);
        $defaultScopes = array_values(array_filter(
            $availability->grantable($user, $requestedFullAccess ? array_column($requestedScopes, 'value') : $configuredDefaults),
            // Unknown integration semantics also require an explicit choice.
            fn (string $value): bool => TokenScope::tryFrom($value) !== null
                && ! str_ends_with($value, ':delete') && ! str_ends_with($value, ':admin'),
        ));

        $view = Inertia::render('statamic-mcp::McpConsentPage', [
            'consentOffer' => app(ConsentOffer::class)->issue($request, UserIds::normalize($user->id()), array_column($requestedScopes, 'value')),
            'client' => ['name' => $client->clientName, 'verified' => $client->isCimd,
                'host' => $client->isCimd ? parse_url($client->clientId, PHP_URL_HOST) : null,
                'logo' => $client->isCimd ? $client->logoUri : null],
            'siteName' => config('app.name'),
            'userName' => $user->email(),
            'redirectHost' => parse_url($redirectUri, PHP_URL_HOST),
            'localRedirects' => collect($client->redirectUris)->every(fn (string $uri): bool => in_array(parse_url($uri, PHP_URL_HOST), ['localhost', '127.0.0.1', '[::1]', '::1'], true)),
            'submitUrl' => route('statamic.cp.statamic-mcp.oauth.approve'),
            'csrfToken' => csrf_token(),
            'scopes' => $requestedScopes,
            'defaultScopes' => $defaultScopes,
            'oauthParams' => [
                'client_id' => $clientId,
                'redirect_uri' => $redirectUri,
                'state' => $state,
                'code_challenge' => $codeChallenge,
                'code_challenge_method' => 'S256',
                'scope' => $scope,
                'resource' => $request->input('resource'),
            ],
        ])->toResponse($request);

        // The consent screen authorizes a grant with one click; it must not be
        // framed, so a cross-origin page cannot overlay it for a clickjacked
        // approval.
        $view->headers->add([
            'X-Frame-Options' => 'DENY',
            'Content-Security-Policy' => "frame-ancestors 'none'",
        ]);

        return $view;
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
            abort(400, __('statamic-mcp::messages.oauth.unknown_client_id'));
        }

        if (! RedirectUriMatcher::matches($redirectUri, $client->redirectUris)) {
            abort(400, __('statamic-mcp::messages.oauth.invalid_redirect_uri'));
        }

        try {
            $resource = ResourceIndicator::fromRequest($request);
        } catch (OAuthException $error) {
            abort(400, $error->getMessage());
        }

        if ($decision !== 'approve') {
            return redirect($redirectUri . (str_contains($redirectUri, '?') ? '&' : '?') . http_build_query([
                'error' => 'access_denied',
                'state' => $state,
                'iss' => $request->getSchemeAndHttpHost(),
            ]));
        }

        $user = User::current();

        if ($user === null) {
            return redirect($redirectUri . (str_contains($redirectUri, '?') ? '&' : '?') . http_build_query([
                'error' => 'access_denied',
                'error_description' => __('statamic-mcp::messages.oauth.unauthenticated'),
                'state' => $state,
                'iss' => $request->getSchemeAndHttpHost(),
            ]));
        }

        $userId = UserIds::normalize($user->id());

        $offer = app(ConsentOffer::class)->consume($request, $userId);
        $allowedScopes = app(ScopeAvailability::class)->grantable($user, $offer['scopes']);

        if ($allowedScopes === []) {
            return redirect($redirectUri . (str_contains($redirectUri, '?') ? '&' : '?') . http_build_query([
                'error' => 'invalid_scope',
                'error_description' => __('statamic-mcp::messages.oauth.no_available_scopes'),
                'state' => $state,
                'iss' => $request->getSchemeAndHttpHost(),
            ]));
        }

        // Get user-selected scopes from checkboxes
        /** @var mixed $rawScopes */
        $rawScopes = $request->input('scopes', []);
        $selectedScopes = is_array($rawScopes) ? array_values(array_filter($rawScopes, 'is_string')) : [];

        // Only allow scopes that were in the original request (prevent scope escalation)
        $scopes = array_values(array_intersect($selectedScopes, $allowedScopes));
        if (empty($scopes)) {
            return redirect($redirectUri . (str_contains($redirectUri, '?') ? '&' : '?') . http_build_query([
                'error' => 'invalid_scope',
                'error_description' => __('statamic-mcp::messages.oauth.select_permission'),
                'state' => $state,
                'iss' => $request->getSchemeAndHttpHost(),
            ]));
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
            new CapabilitySnapshot(array_intersect_assoc(
                $offer['capabilities']->actions,
                app(CapabilityRegistry::class)->forScopes($scopes)->actions,
            ), $offer['capabilities']->grantId, array_intersect_key($offer['capabilities']->scopes, array_flip($scopes)), $resource),
        );

        return redirect($redirectUri . (str_contains($redirectUri, '?') ? '&' : '?') . http_build_query([
            'code' => $code,
            'state' => $state,
            'iss' => $request->getSchemeAndHttpHost(),
        ]));
    }

    /**
     * Attempt to resolve a client_id via CIMD (Client ID Metadata Document).
     *
     * Returns null if CIMD is disabled, the client_id is not a valid CIMD URL,
     * or fetching or validating the document fails. Why it failed goes to the
     * log, not to the browser: the reason would tell anyone probing with a
     * crafted client_id what this server can reach.
     */
    private function resolveClientViaCimd(string $clientId): ?OAuthClient
    {
        $cimdClientId = CimdClientId::tryFrom($clientId);

        if ($cimdClientId === null || ! (bool) config('statamic.mcp.oauth.cimd_enabled', true)) {
            return null;
        }

        try {
            return OAuthClient::fromCimdMetadata($this->cimdResolver->resolve($cimdClientId));
        } catch (CimdFetchException|CimdValidationException $e) {
            Log::info('MCP OAuth: client metadata document could not be used.', ['client_id' => $clientId, 'reason' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * An error page for a request that never reached the consent screen.
     * Nothing is sent to the client's redirect_uri (see show()).
     */
    private function errorPage(string $error, string $description): Response
    {
        /** @var view-string $viewName */
        $viewName = 'statamic-mcp::oauth.error';

        return response(view($viewName, ['error' => $error, 'description' => $description]), 400)->withHeaders([
            'X-Frame-Options' => 'DENY',
            'Content-Security-Policy' => "frame-ancestors 'none'",
        ]);
    }
}

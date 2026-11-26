<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Http\Controllers\CP;

use Cboxdk\StatamicMcp\Approvals\Approvals;
use Cboxdk\StatamicMcp\Auth\ScopeAvailability;
use Cboxdk\StatamicMcp\Auth\TokenService;
use Cboxdk\StatamicMcp\Authorization\InstallationProfile;
use Cboxdk\StatamicMcp\Authorization\PersonalTokenConsent;
use Cboxdk\StatamicMcp\Http\Controllers\CP\Concerns\ResolvesUserId;
use Cboxdk\StatamicMcp\Services\ClientConfigGenerator;
use Cboxdk\StatamicMcp\Services\StatsService;
use Cboxdk\StatamicMcp\Storage\Tokens\McpTokenData;
use Cboxdk\StatamicMcp\Support\McpConfig;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Statamic\Facades\User;
use Statamic\Http\Controllers\CP\CpController;

class DashboardController extends CpController
{
    use ResolvesUserId;

    private StatsService $statsService;

    private TokenService $tokenService;

    private ClientConfigGenerator $configGenerator;

    public function __construct(
        Request $request,
        StatsService $statsService,
        TokenService $tokenService,
        ClientConfigGenerator $configGenerator,
    ) {
        parent::__construct($request);

        $this->statsService = $statsService;
        $this->tokenService = $tokenService;
        $this->configGenerator = $configGenerator;
    }

    /**
     * Display the user MCP page with tabs: Connect, My Tokens, System.
     */
    public function index(): Response
    {
        $this->authorize('view mcp dashboard');

        $userId = $this->resolveUserId();
        $tokens = $this->tokenService->listTokensForUser($userId);

        return Inertia::render('statamic-mcp::McpPage', [
            'tokens' => $tokens->map(fn (McpTokenData $token): array => $this->serializeToken($token))->values()->all(),
            'pendingApprovals' => app(Approvals::class)->pendingCountFor('user:' . $userId),
            'approvalsUrl' => cp_route('statamic-mcp.approvals.index'),
            // Only what this user can exercise (see ScopeAvailability).
            'availableScopes' => app(ScopeAvailability::class)->options(User::current()),
            'httpProfile' => app(InstallationProfile::class)->name('http'),
            'scopeRestrictions' => (object) app(ScopeAvailability::class)->httpProfileRestrictions(),
            'capabilityOffer' => app(PersonalTokenConsent::class)->offer(request()),
            'clients' => $this->configGenerator->getAvailableClients(),
            'webEnabled' => (bool) config('statamic.mcp.web.enabled', false),
            'oauthEnabled' => (bool) config('statamic.mcp.oauth.enabled', true),
            'mcpEndpoint' => $this->getMcpEndpoint(),
            'maxTokenLifetimeDays' => McpConfig::optionalInt('security.max_token_lifetime_days', 365),
        ]);
    }

    /**
     * Display the admin MCP page with tabs: All Tokens, Activity, System.
     */
    public function admin(): Response
    {
        $this->authorize('manage all mcp tokens');

        $allTokens = $this->tokenService->listAllTokens();

        // Collect available users for the activity user filter
        $availableUsers = User::all()->map(function ($u): array {
            /** @var \Statamic\Contracts\Auth\User $u */
            return [
                'id' => $u->id(),
                'email' => $u->email(),
                'name' => $u->name(),
            ];
        })->values()->all();

        // Collect registered MCP tool names for the activity tool filter
        /** @var array<int, string> $toolNames */
        $toolNames = [];
        try {
            $tools = app('mcp.tools');
            if (is_array($tools)) {
                $toolNames = array_values(array_map(function (mixed $tool): string {
                    if (is_object($tool) && method_exists($tool, 'name')) {
                        /** @var string */
                        return $tool->name();
                    }

                    return is_string($tool) ? $tool : '';
                }, $tools));
            }
        } catch (\Throwable) {
            // Tools may not be registered in all contexts
        }

        return Inertia::render('statamic-mcp::McpAdminPage', [
            'allTokens' => $allTokens->map(fn (McpTokenData $token): array => $this->serializeToken($token, true))->values()->all(),
            'availableScopes' => app(ScopeAvailability::class)->options(User::current()),
            'httpProfile' => app(InstallationProfile::class)->name('http'),
            'scopeRestrictions' => (object) app(ScopeAvailability::class)->httpProfileRestrictions(),
            'capabilityOffer' => app(PersonalTokenConsent::class)->offer(request()),
            'availableUsers' => $availableUsers,
            'availableTools' => $toolNames,
            'clients' => $this->configGenerator->getAvailableClients(),
            'systemStats' => $this->statsService->getSystemStats(),
            'webEnabled' => (bool) config('statamic.mcp.web.enabled', false),
            'mcpEndpoint' => $this->getMcpEndpoint(),
            'maxTokenLifetimeDays' => McpConfig::optionalInt('security.max_token_lifetime_days', 365),
        ]);
    }

    /**
     * Serialize a token model to an array for the frontend.
     *
     * @return array<string, mixed>
     */
    private function serializeToken(McpTokenData $token, bool $includeUser = false): array
    {
        $data = [
            'id' => $token->id,
            'name' => $token->name,
            'scopes' => $token->scopes,
            'is_oauth' => $token->oauthClientId !== null || str_contains($token->name, '(OAuth)'),
            'oauth_client_id' => $token->oauthClientId,
            'oauth_client_name' => $token->oauthClientName,
            'last_used_at' => $token->lastUsedAt?->toIso8601String(),
            'expires_at' => $token->expiresAt?->toIso8601String(),
            'created_at' => $token->createdAt->toIso8601String(),
            'is_expired' => $token->isExpired(),
            // Only the owner may regenerate: it hands over the new secret.
            'is_own' => $token->userId === $this->resolveUserId(),
        ];

        if ($includeUser) {
            $statamicUser = User::find($token->userId);
            $data['user_id'] = $token->userId;
            $data['user_name'] = $statamicUser?->name() ?? __('statamic-mcp::messages.unknown_user');
            $data['user_email'] = $statamicUser?->email() ?? '';
        }

        return $data;
    }

    /**
     * Get the full MCP endpoint URL.
     */
    private function getMcpEndpoint(): string
    {
        /** @var string $baseUrl */
        $baseUrl = config('app.url', 'https://your-site.test');

        /** @var string $mcpPath */
        $mcpPath = config('statamic.mcp.web.path', '/mcp/statamic');

        return rtrim($baseUrl, '/') . '/' . ltrim($mcpPath, '/');
    }
}

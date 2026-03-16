<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Http\Controllers\CP;

use Cboxdk\StatamicMcp\Auth\TokenScope;
use Cboxdk\StatamicMcp\Auth\TokenService;
use Cboxdk\StatamicMcp\Services\ClientConfigGenerator;
use Cboxdk\StatamicMcp\Services\StatsService;
use Cboxdk\StatamicMcp\Storage\Tokens\McpTokenData;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Statamic\Facades\User;
use Statamic\Http\Controllers\CP\CpController;

class DashboardController extends CpController
{
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
            'availableScopes' => array_map(
                fn (TokenScope $s): array => [
                    'value' => $s->value,
                    'label' => $s->label(),
                    'group' => $s->group(),
                ],
                TokenScope::all()
            ),
            'clients' => $this->configGenerator->getAvailableClients(),
            'webEnabled' => (bool) config('statamic.mcp.web.enabled', false),
            'mcpEndpoint' => $this->getMcpEndpoint(),
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
            'availableScopes' => array_map(
                fn (TokenScope $s): array => [
                    'value' => $s->value,
                    'label' => $s->label(),
                    'group' => $s->group(),
                ],
                TokenScope::all()
            ),
            'availableUsers' => $availableUsers,
            'availableTools' => $toolNames,
            'systemStats' => $this->statsService->getSystemStats(),
            'webEnabled' => (bool) config('statamic.mcp.web.enabled', false),
            'mcpEndpoint' => $this->getMcpEndpoint(),
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
            'last_used_at' => $token->lastUsedAt?->toIso8601String(),
            'expires_at' => $token->expiresAt?->toIso8601String(),
            'created_at' => $token->createdAt->toIso8601String(),
            'is_expired' => $token->expiresAt !== null && now()->greaterThan($token->expiresAt),
        ];

        if ($includeUser) {
            $statamicUser = User::find($token->userId);
            $data['user_id'] = $token->userId;
            $data['user_name'] = $statamicUser?->name() ?? 'Unknown';
            $data['user_email'] = $statamicUser?->email() ?? '';
        }

        return $data;
    }

    /**
     * Resolve the current authenticated user's ID.
     */
    private function resolveUserId(): string
    {
        $user = User::current();

        /** @var string $userId */
        $userId = $user ? $user->id() : '';

        return $userId;
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

<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Http\Controllers\CP;

use Carbon\Carbon;
use Cboxdk\StatamicMcp\Auth\ScopeAvailability;
use Cboxdk\StatamicMcp\Auth\TokenScope;
use Cboxdk\StatamicMcp\Auth\TokenService;
use Cboxdk\StatamicMcp\Http\Controllers\CP\Concerns\ResolvesUserId;
use Cboxdk\StatamicMcp\Storage\Tokens\McpTokenData;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Statamic\Facades\User;
use Statamic\Http\Controllers\CP\CpController;

class TokenController extends CpController
{
    use ResolvesUserId;

    private TokenService $tokenService;

    public function __construct(Request $request, TokenService $tokenService)
    {
        parent::__construct($request);

        $this->tokenService = $tokenService;
    }

    /**
     * Create a new MCP token for the current user.
     */
    public function store(Request $request): JsonResponse
    {
        $this->authorize('create mcp tokens');

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'scopes' => ['required', 'array', 'min:1'],
            'scopes.*' => ['required', 'string'],
            'expires_at' => ['nullable', 'date', 'after:now'],
        ]);

        $scopes = TokenScope::resolveMany($validated['scopes']);

        if ($scopes === []) {
            return response()->json([
                'message' => 'Invalid scopes provided. Valid scopes: ' . implode(', ', array_map(
                    fn (TokenScope $s): string => $s->value,
                    TokenScope::all()
                )),
            ], 422);
        }

        if ($refused = $this->refuseUnavailableScopes(User::current(), $scopes)) {
            return $refused;
        }

        $expiresAt = isset($validated['expires_at'])
            ? Carbon::parse($validated['expires_at'])
            : null;

        $userId = $this->resolveUserId();

        $result = $this->tokenService->createToken(
            $userId,
            $validated['name'],
            $scopes,
            $expiresAt,
        );

        return response()->json([
            'token' => $result['token'],
            'model' => [
                'id' => $result['model']->id,
                'name' => $result['model']->name,
                'scopes' => $result['model']->scopes,
                'expires_at' => $result['model']->expiresAt?->toIso8601String(),
                'created_at' => $result['model']->createdAt->toIso8601String(),
            ],
            'message' => 'Token created successfully. Copy your token now -- it will not be shown again.',
        ], 201);
    }

    /**
     * Update an existing MCP token's name, scopes, or expiration.
     */
    public function update(Request $request, string $tokenId): JsonResponse
    {
        $this->authorize('create mcp tokens');

        $token = $this->findTokenWithPermission($tokenId, 'manage all mcp tokens');

        if ($token === null) {
            return response()->json([
                'message' => 'Token not found.',
            ], 404);
        }

        $validated = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'scopes' => ['sometimes', 'required', 'array', 'min:1'],
            'scopes.*' => ['required', 'string'],
            'expires_at' => ['nullable', 'date', 'after:now'],
            'clear_expiry' => ['sometimes', 'boolean'],
        ]);

        $scopes = null;

        if (isset($validated['scopes'])) {
            $scopes = TokenScope::resolveMany($validated['scopes']);

            if ($scopes === []) {
                return response()->json([
                    'message' => 'Invalid scopes provided. Valid scopes: ' . implode(', ', array_map(
                        fn (TokenScope $s): string => $s->value,
                        TokenScope::all()
                    )),
                ], 422);
            }

            // Capped by the token's owner, not by whoever edits it: an admin
            // granting an editor's token a scope the editor cannot use makes
            // a dead scope, not access.
            if ($refused = $this->refuseUnavailableScopes(User::find($token->userId) ?? User::current(), $scopes)) {
                return $refused;
            }
        }

        $expiresAt = isset($validated['expires_at'])
            ? Carbon::parse($validated['expires_at'])
            : null;

        $clearExpiry = (bool) ($validated['clear_expiry'] ?? false);

        $updated = $this->tokenService->updateToken(
            $tokenId,
            $validated['name'] ?? null,
            $scopes,
            $expiresAt,
            $clearExpiry,
        );

        if ($updated === null) {
            return response()->json([
                'message' => 'Failed to update token.',
            ], 500);
        }

        return response()->json([
            'model' => [
                'id' => $updated->id,
                'name' => $updated->name,
                'scopes' => $updated->scopes,
                'expires_at' => $updated->expiresAt?->toIso8601String(),
                'created_at' => $updated->createdAt->toIso8601String(),
                'last_used_at' => $updated->lastUsedAt?->toIso8601String(),
                'is_expired' => $updated->isExpired(),
            ],
            'message' => 'Token updated successfully.',
        ]);
    }

    /**
     * Regenerate a token's secret. Returns the new plain-text token once.
     */
    public function regenerate(string $tokenId): JsonResponse
    {
        $this->authorize('create mcp tokens');

        $token = $this->findTokenWithPermission($tokenId, 'manage all mcp tokens');

        if ($token === null) {
            return response()->json([
                'message' => 'Token not found.',
            ], 404);
        }

        if ($token->oauthClientId !== null || str_contains($token->name, '(OAuth)')) {
            return response()->json([
                'message' => 'OAuth tokens cannot be regenerated. The integration would lose access. Revoke the token and re-authorize instead.',
            ], 403);
        }

        $result = $this->tokenService->regenerateToken($tokenId);

        if ($result === null) {
            return response()->json([
                'message' => 'Failed to regenerate token.',
            ], 500);
        }

        return response()->json([
            'token' => $result['token'],
            'model' => [
                'id' => $result['model']->id,
                'name' => $result['model']->name,
                'scopes' => $result['model']->scopes,
                'expires_at' => $result['model']->expiresAt?->toIso8601String(),
                'created_at' => $result['model']->createdAt->toIso8601String(),
                'last_used_at' => $result['model']->lastUsedAt?->toIso8601String(),
                'is_expired' => $result['model']->isExpired(),
            ],
            'message' => 'Token regenerated. Copy your new token now — it will not be shown again.',
        ]);
    }

    /**
     * Revoke (delete) a specific token.
     *
     * Users with 'revoke mcp tokens' can revoke their own tokens.
     * Users with 'revoke all mcp tokens' can revoke any token (admin).
     */
    public function destroy(string $tokenId): JsonResponse
    {
        $this->authorize('revoke mcp tokens');

        $token = $this->findTokenWithPermission($tokenId, 'revoke all mcp tokens');

        if ($token === null) {
            return response()->json([
                'message' => 'Token not found.',
            ], 404);
        }

        $this->tokenService->revokeToken($tokenId);

        return response()->json([
            'message' => 'Token revoked successfully.',
        ]);
    }

    /**
     * A 422 naming the scopes the user cannot exercise, or null when every
     * scope is available to them.
     *
     * @param  array<int, TokenScope>  $scopes
     */
    private function refuseUnavailableScopes(?\Statamic\Contracts\Auth\User $user, array $scopes): ?JsonResponse
    {
        $availability = app(ScopeAvailability::class);
        $unavailable = $availability->reject($user, $scopes);

        if ($unavailable === []) {
            return null;
        }

        $list = fn (array $list): string => implode(', ', array_map(fn (TokenScope $s): string => $s->value, $list));

        return response()->json([
            'message' => 'These scopes are not available to this user: ' . $list($unavailable)
                . '. Available scopes: ' . $list($availability->forUser($user)),
        ], 422);
    }

    /**
     * Find a token by ID, respecting admin permissions.
     *
     * If the current user has the given admin permission (or is super),
     * searches all tokens. Otherwise, only searches the user's own tokens.
     */
    private function findTokenWithPermission(string $tokenId, string $adminPermission): ?McpTokenData
    {
        $user = User::current();
        $isAdmin = $user && ($user->isSuper() || $user->hasPermission($adminPermission));

        if ($isAdmin) {
            return $this->tokenService->listAllTokens()->firstWhere('id', $tokenId);
        }

        $userId = $this->resolveUserId();

        return $this->tokenService->listTokensForUser($userId)->firstWhere('id', $tokenId);
    }
}

<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Storage\Tokens;

use Carbon\Carbon;
use Cboxdk\StatamicMcp\Auth\McpToken;
use Cboxdk\StatamicMcp\Contracts\TokenStore;
use Illuminate\Support\Collection;

class DatabaseTokenStore extends BaseTokenStore implements TokenStore
{
    /**
     * @param  array<int, string>  $scopes
     */
    public function create(
        string $userId,
        string $name,
        string $tokenHash,
        array $scopes,
        ?Carbon $expiresAt,
        ?string $oauthClientId = null,
        ?string $oauthClientName = null,
    ): McpTokenData {
        $model = McpToken::create([
            'user_id' => $userId,
            'name' => $name,
            'token' => $tokenHash,
            'scopes' => $scopes,
            'expires_at' => $expiresAt,
            'oauth_client_id' => $oauthClientId,
            'oauth_client_name' => $oauthClientName,
        ]);

        return $this->toData($model);
    }

    public function findByHash(string $tokenHash): ?McpTokenData
    {
        $model = McpToken::where('token', $tokenHash)->first();

        return $model !== null ? $this->toData($model) : null;
    }

    public function find(string $id): ?McpTokenData
    {
        $model = McpToken::find($id);

        return $model !== null ? $this->toData($model) : null;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(string $id, array $data): ?McpTokenData
    {
        $model = McpToken::find($id);

        if ($model === null) {
            return null;
        }

        /** @var array<string, mixed> $attributes */
        $attributes = [];

        $columnMap = [
            'tokenHash' => 'token',
            'expiresAt' => 'expires_at',
            'name' => 'name',
            'scopes' => 'scopes',
        ];

        foreach ($columnMap as $dtoKey => $column) {
            if (array_key_exists($dtoKey, $data)) {
                $attributes[$column] = $data[$dtoKey];
            }
        }

        if ($attributes !== []) {
            $model->forceFill($attributes)->save();
        }

        return $this->toData($model);
    }

    public function delete(string $id): bool
    {
        return McpToken::where('id', $id)->delete() > 0;
    }

    public function deleteForUser(string $userId): int
    {
        return McpToken::where('user_id', $userId)->delete();
    }

    /**
     * @return Collection<int, McpTokenData>
     */
    public function listForUser(string $userId): Collection
    {
        return McpToken::where('user_id', $userId)
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (McpToken $model): McpTokenData => $this->toData($model))
            ->values();
    }

    /**
     * @return Collection<int, McpTokenData>
     */
    public function listAll(): Collection
    {
        return McpToken::orderByDesc('created_at')
            ->get()
            ->map(fn (McpToken $model): McpTokenData => $this->toData($model))
            ->values();
    }

    public function import(McpTokenData $token): McpTokenData
    {
        $model = new McpToken;
        $model->id = $token->id;
        $model->forceFill([
            'user_id' => $token->userId,
            'name' => $token->name,
            'token' => $token->tokenHash,
            'scopes' => $token->scopes,
            'oauth_client_id' => $token->oauthClientId,
            'oauth_client_name' => $token->oauthClientName,
            'last_used_at' => $token->lastUsedAt,
            'expires_at' => $token->expiresAt,
            'created_at' => $token->createdAt,
            'updated_at' => $token->updatedAt,
        ])->saveQuietly();

        return $this->toData($model);
    }

    public function pruneExpired(): int
    {
        return McpToken::where('expires_at', '<=', now())->delete();
    }

    public function markAsUsed(string $id): void
    {
        McpToken::where('id', $id)->update(['last_used_at' => now()]);
    }

    private function toData(McpToken $model): McpTokenData
    {
        /** @var array<int, string> $scopes */
        $scopes = $model->scopes;

        return new McpTokenData(
            id: $model->id,
            userId: $model->user_id,
            name: $model->name,
            tokenHash: $model->token,
            scopes: $scopes,
            lastUsedAt: $this->parseCarbon($model->last_used_at),
            expiresAt: $this->parseCarbon($model->expires_at),
            createdAt: $this->parseCarbon($model->created_at) ?? Carbon::now(),
            updatedAt: $this->parseCarbon($model->updated_at),
            oauthClientId: $model->oauth_client_id,
            oauthClientName: $model->oauth_client_name,
        );
    }
}

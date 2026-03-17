<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Contracts;

use Carbon\Carbon;
use Cboxdk\StatamicMcp\Storage\Tokens\McpTokenData;
use Illuminate\Support\Collection;

interface TokenStore
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
    ): McpTokenData;

    public function findByHash(string $tokenHash): ?McpTokenData;

    public function find(string $id): ?McpTokenData;

    /**
     * Update token fields. Supported keys: name, scopes, expiresAt, tokenHash.
     * When tokenHash is updated, FileTokenStore MUST also update its hash index.
     *
     * @param  array<string, mixed>  $data
     */
    public function update(string $id, array $data): ?McpTokenData;

    public function delete(string $id): bool;

    public function deleteForUser(string $userId): int;

    /**
     * @return Collection<int, McpTokenData>
     */
    public function listForUser(string $userId): Collection;

    /**
     * @return Collection<int, McpTokenData>
     */
    public function listAll(): Collection;

    /**
     * Import an existing token preserving all fields including ID.
     * Used by mcp:migrate-store to copy tokens between drivers.
     */
    public function import(McpTokenData $token): McpTokenData;

    /**
     * Delete expired tokens. Returns count deleted.
     */
    public function pruneExpired(): int;

    public function markAsUsed(string $id): void;
}

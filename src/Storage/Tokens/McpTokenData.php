<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Storage\Tokens;

use Carbon\Carbon;
use Cboxdk\StatamicMcp\Actions\CapabilitySnapshot;

/**
 * Immutable data transfer object for MCP tokens.
 *
 * Pure data — no business logic. Scope checking and expiry
 * validation stay in TokenService.
 */
class McpTokenData
{
    /**
     * @param  array<int, string>  $scopes
     */
    public function __construct(
        public readonly string $id,
        public readonly string $userId,
        public readonly string $name,
        public readonly string $tokenHash,
        public readonly array $scopes,
        public readonly ?Carbon $lastUsedAt,
        public readonly ?Carbon $expiresAt,
        public readonly Carbon $createdAt,
        public readonly ?Carbon $updatedAt = null,
        public readonly ?string $oauthClientId = null,
        public readonly ?string $oauthClientName = null,
        public readonly ?CapabilitySnapshot $capabilities = null,
    ) {}

    /** Transport-private credential revision; never include in events or durable intent. */
    public function credentialRevision(): string
    {
        return hash('sha256', 'mcp-credential:' . $this->id . ':' . $this->tokenHash);
    }

    /**
     * Check whether this token has expired.
     */
    public function isExpired(): bool
    {
        return $this->expiresAt !== null && now()->greaterThanOrEqualTo($this->expiresAt);
    }
}

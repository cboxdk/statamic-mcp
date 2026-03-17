<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Storage\Tokens;

use Carbon\Carbon;

/**
 * Shared helpers for TokenStore implementations.
 *
 * Provides common data-conversion logic used by both
 * FileTokenStore and DatabaseTokenStore. Does NOT implement
 * the TokenStore interface — that stays on the concrete classes.
 */
abstract class BaseTokenStore
{
    /**
     * Parse a mixed value into a Carbon instance, or null.
     *
     * Accepts ISO-8601 strings, Carbon instances (returned as-is),
     * and anything else Carbon::parse() can handle.
     * Returns null for null / non-string / non-Carbon input.
     */
    protected function parseCarbon(mixed $value): ?Carbon
    {
        if ($value instanceof Carbon) {
            return $value;
        }

        if ($value === null || ! is_string($value)) {
            return null;
        }

        return Carbon::parse($value);
    }

    /**
     * Convert a normalized associative array into an McpTokenData DTO.
     *
     * Expected keys (snake_case):
     *   id, user_id, name, token_hash, scopes,
     *   last_used_at, expires_at, created_at, updated_at,
     *   oauth_client_id, oauth_client_name
     *
     * Values may be strings, Carbon instances, arrays, or null.
     * Type guards ensure PHPStan Level 8 compliance regardless of
     * how loosely the underlying storage layer types its data.
     *
     * @param  array<string, mixed>  $data
     */
    protected function arrayToTokenData(array $data): McpTokenData
    {
        /** @var array<int, string> $scopes */
        $scopes = is_array($data['scopes'] ?? null) ? $data['scopes'] : [];

        return new McpTokenData(
            id: is_string($data['id'] ?? null) ? $data['id'] : '',
            userId: is_string($data['user_id'] ?? null) ? $data['user_id'] : '',
            name: is_string($data['name'] ?? null) ? $data['name'] : '',
            tokenHash: is_string($data['token_hash'] ?? null) ? $data['token_hash'] : '',
            scopes: $scopes,
            lastUsedAt: $this->parseCarbon($data['last_used_at'] ?? null),
            expiresAt: $this->parseCarbon($data['expires_at'] ?? null),
            createdAt: $this->parseCarbon($data['created_at'] ?? null) ?? Carbon::now(),
            updatedAt: $this->parseCarbon($data['updated_at'] ?? null),
            oauthClientId: is_string($data['oauth_client_id'] ?? null) ? $data['oauth_client_id'] : null,
            oauthClientName: is_string($data['oauth_client_name'] ?? null) ? $data['oauth_client_name'] : null,
        );
    }
}

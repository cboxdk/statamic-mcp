<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Storage\Tokens;

use Carbon\Carbon;
use Cboxdk\StatamicMcp\Actions\CapabilitySnapshot;

/**
 * Shared helpers for TokenStore implementations.
 *
 * Provides common data-conversion logic used by both
 * FileTokenStore and DatabaseTokenStore. Does NOT implement
 * the TokenStore interface — that stays on the concrete classes.
 */
abstract class BaseTokenStore
{
    /** @return list<string> */
    protected function scopeValues(mixed $value): array
    {
        if (! is_array($value) || ! array_is_list($value)) {
            throw new \UnexpectedValueException('Token scopes must be a list of strings.');
        }
        $scopes = [];
        foreach ($value as $scope) {
            if (! is_string($scope) || $scope === '') {
                throw new \UnexpectedValueException('Token scopes must be a list of strings.');
            }
            $scopes[] = $scope;
        }

        return array_values(array_unique($scopes));
    }

    protected function scopesChanged(mixed $requested, mixed $existing): bool
    {
        $next = $this->scopeValues($requested);
        $previous = $this->scopeValues($existing);
        sort($next);
        sort($previous);

        return $next !== $previous;
    }

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

    /** A damaged expiry must never turn into a non-expiring credential. */
    protected function parseExpiry(mixed $value): ?Carbon
    {
        if ($value === null) {
            return null;
        }
        if ($value instanceof \DateTimeInterface) {
            return Carbon::instance($value);
        }
        if (! is_string($value) || ! preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?(?:Z|[+-]\d{2}:\d{2})$/D', $value)) {
            throw new \UnexpectedValueException('Invalid stored token expiry.');
        }
        $format = str_contains($value, '.') ? '!Y-m-d\TH:i:s.uP' : '!Y-m-d\TH:i:sP';
        $parsed = \DateTimeImmutable::createFromFormat($format, $value);
        $errors = \DateTimeImmutable::getLastErrors();
        if ($parsed === false || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) {
            throw new \UnexpectedValueException('Invalid stored token expiry.');
        }

        return Carbon::instance($parsed);
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
        if (! array_key_exists('expires_at', $data)) {
            throw new \UnexpectedValueException('Stored token expiry is missing.');
        }
        $scopes = $this->scopeValues($data['scopes'] ?? []);

        return new McpTokenData(
            id: is_string($data['id'] ?? null) ? $data['id'] : '',
            userId: is_string($data['user_id'] ?? null) ? $data['user_id'] : '',
            name: is_string($data['name'] ?? null) ? $data['name'] : '',
            tokenHash: is_string($data['token_hash'] ?? null) ? $data['token_hash'] : '',
            scopes: $scopes,
            lastUsedAt: $this->parseCarbon($data['last_used_at'] ?? null),
            expiresAt: $this->parseExpiry($data['expires_at']),
            createdAt: $this->parseCarbon($data['created_at'] ?? null) ?? Carbon::now(),
            updatedAt: $this->parseCarbon($data['updated_at'] ?? null),
            oauthClientId: is_string($data['oauth_client_id'] ?? null) ? $data['oauth_client_id'] : null,
            oauthClientName: is_string($data['oauth_client_name'] ?? null) ? $data['oauth_client_name'] : null,
            capabilities: CapabilitySnapshot::fromStored($data['capabilities'] ?? null),
        );
    }
}

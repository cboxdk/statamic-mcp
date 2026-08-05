<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Auth;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;

class ConfirmationTokenManager
{
    /**
     * Generate an HMAC-signed confirmation token for a specific tool + arguments.
     *
     * @param  array<string, mixed>  $arguments
     */
    public function generate(string $tool, array $arguments): string
    {
        $timestamp = time();
        $nonce = bin2hex(random_bytes(16));
        $arguments = $this->stripConfirmationToken($arguments);
        $payload = $this->buildPayload($tool, $arguments, $timestamp, $nonce);

        $signature = hash_hmac('sha256', $payload, $this->getKey());

        $encoded = json_encode([
            'timestamp' => $timestamp,
            'nonce' => $nonce,
            'arguments' => $arguments,
            'signature' => $signature,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        if ($encoded === false) {
            throw new \InvalidArgumentException('Cannot encode confirmation token: ' . json_last_error_msg());
        }

        $compressed = gzdeflate($encoded, 9);
        if ($compressed === false) {
            throw new \InvalidArgumentException('Cannot compress confirmation token.');
        }

        return Crypt::encryptString(base64_encode($compressed));
    }

    /**
     * Validate a confirmation token against a tool + arguments.
     *
     * @param  array<string, mixed>  $arguments
     */
    public function validate(#[\SensitiveParameter] string $token, string $tool, array $arguments): bool
    {
        $parts = $this->parseToken($token);
        if ($parts === null) {
            return false;
        }

        if ((time() - $parts['timestamp']) > $this->ttl()) {
            return false;
        }

        // Rebuild payload and compare signatures
        $expectedPayload = $this->buildPayload($tool, $parts['arguments'], $parts['timestamp'], $parts['nonce']);
        $expectedSignature = hash_hmac('sha256', $expectedPayload, $this->getKey());

        return hash_equals($expectedSignature, $parts['signature'])
            && $this->canonicalArgumentsMatch($arguments, $parts['arguments']);
    }

    /**
     * Validate a confirmation token and return the originally confirmed arguments.
     *
     * @param  array<string, mixed>  $arguments
     *
     * @return array<string, mixed>|null
     */
    public function validatedArguments(#[\SensitiveParameter] string $token, string $tool, array $arguments): ?array
    {
        if (! $this->validate($token, $tool, $arguments)) {
            return null;
        }

        return $this->parseToken($token)['arguments'] ?? null;
    }

    /**
     * Check if confirmation tokens are enabled for the current environment.
     */
    public function isEnabled(): bool
    {
        /** @var bool|null $enabled */
        $enabled = config('statamic.mcp.confirmation.enabled');

        if ($enabled !== null) {
            return (bool) $enabled;
        }

        // Auto-detect: enabled in production only
        return app()->environment('production');
    }

    /**
     * Build the canonical payload string for HMAC signing.
     *
     * @param  array<string, mixed>  $arguments
     */
    private function buildPayload(string $tool, array $arguments, int $timestamp, string $nonce): string
    {
        $arguments = $this->stripConfirmationToken($arguments);
        $exact = json_encode($arguments, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        $canonicalArguments = $this->canonicalize($arguments);

        $canonical = json_encode($canonicalArguments, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        if ($canonical === false || $exact === false) {
            throw new \InvalidArgumentException('Cannot canonicalize arguments: ' . json_last_error_msg());
        }

        return $tool . '|' . $canonical . '|' . $exact . '|' . $timestamp . '|' . $nonce;
    }

    /**
     * Strip the confirmation token from arguments before signing.
     *
     * @param  array<string, mixed>  $arguments
     *
     * @return array<string, mixed>
     */
    private function stripConfirmationToken(array $arguments): array
    {
        unset($arguments['confirmation_token']);

        return $arguments;
    }

    /**
     * Recursively sort associative arrays while preserving list order.
     *
     * @param  array<array-key, mixed>  $value
     *
     * @return array<array-key, mixed>
     */
    private function canonicalize(array $value): array
    {
        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = $this->canonicalize($item);
            }
        }

        if (! array_is_list($value)) {
            ksort($value);
        }

        return $value;
    }

    /**
     * @param  array<string, mixed>  $provided
     * @param  array<string, mixed>  $confirmed
     */
    private function canonicalArgumentsMatch(array $provided, array $confirmed): bool
    {
        $provided = $this->canonicalize($this->stripConfirmationToken($provided));
        $confirmed = $this->canonicalize($this->stripConfirmationToken($confirmed));

        return json_encode($provided, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
            === json_encode($confirmed, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * @return array{timestamp: int, nonce: string, arguments: array<string, mixed>, signature: string}|null
     */
    private function parseToken(#[\SensitiveParameter] string $token): ?array
    {
        if ($token === '') {
            return null;
        }

        try {
            $encryptedPayload = Crypt::decryptString($token);
        } catch (DecryptException) {
            return null;
        }

        $compressed = base64_decode($encryptedPayload, true);
        if ($compressed === false) {
            return null;
        }

        $decoded = gzinflate($compressed);
        if ($decoded === false) {
            return null;
        }

        $payload = json_decode($decoded, true);
        if (! is_array($payload)) {
            return null;
        }

        $timestamp = $payload['timestamp'] ?? null;
        $nonce = $payload['nonce'] ?? null;
        $arguments = $payload['arguments'] ?? null;
        $signature = $payload['signature'] ?? null;

        if (! is_int($timestamp) || ! is_string($nonce) || $nonce === '' || ! is_array($arguments) || ! is_string($signature) || $signature === '') {
            return null;
        }

        $confirmedArguments = [];
        foreach ($arguments as $key => $value) {
            if (! is_string($key)) {
                return null;
            }

            $confirmedArguments[$key] = $value;
        }

        return [
            'timestamp' => $timestamp,
            'nonce' => $nonce,
            'arguments' => $confirmedArguments,
            'signature' => $signature,
        ];
    }

    private function ttl(): int
    {
        /** @var int $ttl */
        $ttl = config('statamic.mcp.confirmation.ttl', 300);

        return $ttl;
    }

    /**
     * Get the application key used for HMAC signing.
     */
    private function getKey(): string
    {
        /** @var string $key */
        $key = config('app.key', '');

        // Strip the base64: prefix if present
        if (str_starts_with($key, 'base64:')) {
            $decoded = base64_decode(substr($key, 7), true);

            return $decoded !== false ? $decoded : $key;
        }

        return $key;
    }
}

<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Auth;

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
        $payload = $this->buildPayload($tool, $arguments, $timestamp);

        $signature = hash_hmac('sha256', $payload, $this->getKey());

        return base64_encode($timestamp . '.' . $signature);
    }

    /**
     * Validate a confirmation token against a tool + arguments.
     *
     * @param  array<string, mixed>  $arguments
     */
    public function validate(string $token, string $tool, array $arguments): bool
    {
        if ($token === '') {
            return false;
        }

        $decoded = base64_decode($token, true);
        if ($decoded === false) {
            return false;
        }

        $dotPos = strpos($decoded, '.');
        if ($dotPos === false) {
            return false;
        }

        $timestampStr = substr($decoded, 0, $dotPos);
        $signature = substr($decoded, $dotPos + 1);

        if (! is_numeric($timestampStr) || $signature === '') {
            return false;
        }

        $timestamp = (int) $timestampStr;

        // Check expiry
        /** @var int $ttl */
        $ttl = config('statamic.mcp.confirmation.ttl', 300);
        if ((time() - $timestamp) > $ttl) {
            return false;
        }

        // Rebuild payload and compare signatures
        $expectedPayload = $this->buildPayload($tool, $arguments, $timestamp);
        $expectedSignature = hash_hmac('sha256', $expectedPayload, $this->getKey());

        return hash_equals($expectedSignature, $signature);
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
    private function buildPayload(string $tool, array $arguments, int $timestamp): string
    {
        // Strip the confirmation_token from arguments before canonicalizing
        unset($arguments['confirmation_token']);

        // Sort keys for canonical ordering
        ksort($arguments);

        $canonical = json_encode($arguments, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return $tool . '|' . $canonical . '|' . $timestamp;
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

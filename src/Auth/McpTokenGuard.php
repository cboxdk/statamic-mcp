<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Auth;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Auth\Guard;
use Illuminate\Http\Request;
use Statamic\Facades\User;

class McpTokenGuard implements Guard
{
    private ?Authenticatable $user = null;

    public function __construct(
        private readonly TokenService $tokenService,
        private readonly Request $request,
    ) {}

    /**
     * Determine if the current user is authenticated.
     */
    public function check(): bool
    {
        return $this->user() !== null;
    }

    /**
     * Determine if the current user is a guest.
     */
    public function guest(): bool
    {
        return ! $this->check();
    }

    /**
     * Get the currently authenticated user.
     */
    public function user(): ?Authenticatable
    {
        if ($this->user !== null) {
            return $this->user;
        }

        $token = $this->extractBearerToken();

        if ($token === null) {
            return null;
        }

        $mcpToken = $this->tokenService->validateToken($token);

        if ($mcpToken === null) {
            return null;
        }

        $statamicUser = User::find($mcpToken->userId);

        if ($statamicUser === null) {
            return null;
        }

        /** @var Authenticatable $statamicUser */
        $this->user = $statamicUser;

        return $this->user;
    }

    /**
     * Get the ID for the currently authenticated user.
     */
    public function id(): int|string|null
    {
        $user = $this->user();

        return $user?->getAuthIdentifier();
    }

    /**
     * Validate a user's credentials.
     *
     * @param  array<string, mixed>  $credentials
     */
    public function validate(array $credentials = []): bool
    {
        $token = $credentials['token'] ?? null;

        if (! is_string($token)) {
            return false;
        }

        return $this->tokenService->validateToken($token) !== null;
    }

    /**
     * Determine if the guard has a user instance.
     */
    public function hasUser(): bool
    {
        return $this->user !== null;
    }

    /**
     * Set the current user.
     *
     * @return $this
     */
    public function setUser(Authenticatable $user): static
    {
        $this->user = $user;

        return $this;
    }

    /**
     * Extract the bearer token from the request.
     */
    private function extractBearerToken(): ?string
    {
        $header = $this->request->header('Authorization');

        if ($header === null || ! str_starts_with($header, 'Bearer ')) {
            return null;
        }

        $token = substr($header, 7);

        return $token !== '' ? $token : null;
    }
}

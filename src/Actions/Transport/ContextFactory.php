<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Actions\Transport;

use Cboxdk\StatamicMcp\Actions\ExecutionContext;
use Cboxdk\StatamicMcp\Auth\TokenService;
use Cboxdk\StatamicMcp\Storage\Tokens\McpTokenData;
use Cboxdk\StatamicMcp\Support\McpContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Str;
use Statamic\Facades\Site;

/** Transport-owned identity; tool arguments may select a site, never an actor or grant. */
final class ContextFactory
{
    public function __construct(private readonly TokenService $tokens) {}

    public function create(?string $site = null, ?string $operationId = null): ExecutionContext
    {
        $transport = McpContext::isCli() ? 'stdio' : 'http';
        if ($transport === 'http') {
            $token = request()->attributes->get('mcp_token');
        } else {
            $secret = config('statamic.mcp.stdio.token');
            $token = is_string($secret) && $secret !== '' ? $this->tokens->findByPlainText($secret) : null;
        }
        if (! $token instanceof McpTokenData || $token->isExpired() || $token->capabilities === null) {
            throw new AuthorizationException('A current v4 capability grant is required.');
        }
        $profile = config('statamic.mcp.profiles.' . $transport);
        $timeout = config()->integer('statamic.mcp.security.tool_timeout_seconds', 30);
        if (! is_string($profile) || ! in_array($profile, ['read_only', 'editor', 'operator'], true)
            || $timeout < 1 || $timeout > 300) {
            throw new AuthorizationException('MCP execution is not configured.');
        }

        if ($site === null) {
            $default = Site::default();
            if (! $default instanceof \Statamic\Sites\Site) {
                throw new AuthorizationException('Default site is unavailable.');
            }
            $site = $default->handle();
        }

        return new ExecutionContext($token->userId, $token->capabilities->grantId,
            $site, $profile, $transport, $operationId ?? (string) Str::uuid(),
            hrtime(true) + $timeout * 1_000_000_000, $token->id);
    }
}

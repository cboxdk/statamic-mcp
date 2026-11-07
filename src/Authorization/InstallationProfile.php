<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Authorization;

use Cboxdk\StatamicMcp\Support\McpContext;

/** Installation limits only: never a replacement for scopes or Statamic policies. */
final class InstallationProfile
{
    private const READ_DOMAINS = ['entries', 'terms', 'globals', 'assets', 'blueprints', 'structures'];

    private const READ_ACTIONS = ['list', 'get', 'search', 'schema', 'revisions', 'get_revision', 'list_revisions'];

    public function name(): string
    {
        $transport = McpContext::isCli() ? 'stdio' : 'http';
        $profile = config("statamic.mcp.profiles.{$transport}");

        return is_string($profile) && in_array($profile, ['read_only', 'editor', 'operator'], true) ? $profile : 'disabled';
    }

    /** @param array<string, mixed> $arguments */
    public function denial(?string $domain, string $action, array $arguments = []): ?string
    {
        $profile = $this->name();
        if ($profile === 'disabled') {
            return 'MCP transport requires an explicitly configured installation profile.';
        }
        if ($profile === 'operator') {
            return null;
        }
        if ($domain === 'guidance') {
            return null;
        }
        if (! in_array($domain, self::READ_DOMAINS, true)) {
            return 'This domain requires the operator installation profile.';
        }
        if (in_array($action, self::READ_ACTIONS, true)) {
            return null;
        }
        if ($profile !== 'editor' || $domain !== 'entries' || ! in_array($action, ['create', 'update'], true)) {
            return 'This action is not allowed by the installation profile.';
        }
        $data = $arguments['data'] ?? [];
        if (! is_array($data)
            || ($action === 'create' && ! array_key_exists('published', $data))
            || (array_key_exists('published', $data) && $data['published'] !== false)) {
            return 'Editor creates must set data.published to false; updates cannot publish.';
        }
        if (array_key_exists('parent', $data)) {
            return 'Tree changes require the operator installation profile.';
        }

        return null;
    }
}

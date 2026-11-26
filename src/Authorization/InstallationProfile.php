<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Authorization;

use Cboxdk\StatamicMcp\Support\McpContext;

/** Installation limits only: never a replacement for scopes or Statamic policies. */
final class InstallationProfile
{
    private const READ_DOMAINS = ['entries', 'terms', 'globals', 'assets', 'blueprints', 'structures'];

    public function name(): string
    {
        $transport = McpContext::isCli() ? 'stdio' : 'http';
        $profile = config("statamic.mcp.profiles.{$transport}");

        return is_string($profile) && in_array($profile, ['read_only', 'editor', 'operator'], true) ? $profile : 'disabled';
    }

    /**
     * Whether the profile lets any action of this kind run in the domain, so
     * whether a client should be shown the tool at all.
     */
    public function allowsAny(?string $domain, bool $writes): bool
    {
        $profile = $this->name();

        return match (true) {
            $profile === 'disabled' => false,
            $profile === 'operator', $domain === 'guidance' => true,
            ! in_array($domain, self::READ_DOMAINS, true) => false,
            ! $writes => true,
            default => $profile === 'editor' && $domain === 'entries',
        };
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @param  bool  $writes  whether the action changes anything, from its declaration; unknown counts as a write
     */
    public function denial(?string $domain, string $action, array $arguments = [], bool $writes = true): ?string
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
        if (! $writes) {
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

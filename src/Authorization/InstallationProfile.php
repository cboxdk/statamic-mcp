<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Authorization;

use Cboxdk\StatamicMcp\Support\McpContext;
use Statamic\Facades\Entry;
use Statamic\Facades\Site;

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
        if ($action === 'update') {
            $id = $arguments['id'] ?? null;
            $entry = is_string($id) ? Entry::find($id) : null;
            $defaultSite = Site::default();
            $site = is_string($arguments['site'] ?? null) ? $arguments['site']
                : ($defaultSite instanceof \Statamic\Sites\Site ? $defaultSite->handle() : null);
            if ($site === null) {
                return 'Editor updates require a valid site.';
            }
            if ($entry !== null && $entry->site()->handle() !== $site) {
                $entry = $entry->in($site);
            }
            // Until the working-copy adapter is wired, fail closed for live entries.
            if ($entry === null || $entry->published()) {
                return 'Editor updates require an existing unpublished entry.';
            }
        }

        return null;
    }
}

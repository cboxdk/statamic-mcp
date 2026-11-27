<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Auth;

use Cboxdk\StatamicMcp\Mcp\Servers\StatamicMcpServer;
use Cboxdk\StatamicMcp\Mcp\Tools\BaseRouter;
use Illuminate\Support\Facades\File;

/** Project-local client settings only. Never creates an automatic write grant. */
final class V4ClientPermissions
{
    /** @return list<array{path: string, changes: list<string>, applied: bool, error: string|null}> */
    public function run(bool $apply = false): array
    {
        $reports = [];
        foreach (['.claude/settings.json', '.claude/settings.local.json', '.cursor/cli.json'] as $relative) {
            $path = base_path($relative);
            if (! file_exists($path)) {
                continue;
            }
            $report = ['path' => $relative, 'changes' => [], 'applied' => false, 'error' => null];
            try {
                if (is_link($path) || realpath(dirname($path)) !== realpath(base_path()) . '/' . dirname($relative)) {
                    throw new \RuntimeException('Settings must be a regular project-local file, not a symlink.');
                }
                $before = File::get($path);
                $settings = json_decode($before, flags: JSON_THROW_ON_ERROR);
                if (! $settings instanceof \stdClass) {
                    throw new \RuntimeException('Settings must be a JSON object.');
                }
                $report['changes'] = $this->rewrite($settings, str_starts_with($relative, '.cursor/'));
                if ($apply && $report['changes'] !== []) {
                    $after = json_encode($settings, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
                    $this->replace($path, $before, $after);
                    $report['applied'] = true;
                }
            } catch (\Throwable) {
                // Config files may contain credentials; neither content nor
                // parser/IO exception messages belong in a command report.
                $report['error'] = 'Not migrated. Check JSON, permissions, symlinks and the .pre-mcp-v4 backup; the original was not replaced.';
            }
            if ($report['changes'] !== [] || $report['error'] !== null) {
                $reports[] = $report;
            }
        }

        return $reports;
    }

    /** @return list<string> */
    private function rewrite(\stdClass $settings, bool $cursor): array
    {
        if (! property_exists($settings, 'permissions')) {
            return [];
        }
        if (! $settings->permissions instanceof \stdClass) {
            throw new \RuntimeException('Invalid permissions object.');
        }
        $renames = [];
        foreach ([...StatamicMcpServer::CORE_TOOLS, ...StatamicMcpServer::SEARCHABLE_TOOLS] as $class) {
            $tool = app($class);
            if ($tool instanceof BaseRouter) {
                $renames[$tool->name()] = [$tool->facet('read')->name()];
                if ($tool->offersFacet('write')) {
                    $renames[$tool->name()][] = $tool->facet('write')->name();
                }
            }
        }
        $merged = ['statamic-system-discover', 'statamic-system-schema', 'statamic-discovery', 'statamic-schema'];
        $changes = [];
        foreach (['allow', 'ask', 'deny'] as $policy) {
            if (! property_exists($settings->permissions, $policy)) {
                continue;
            }
            $rules = $settings->permissions->{$policy};
            if (! is_array($rules) || ! array_is_list($rules)) {
                throw new \RuntimeException('Invalid permission rules.');
            }
            $rewritten = [];
            foreach ($rules as $rule) {
                if (! is_string($rule)) {
                    throw new \RuntimeException('Invalid permission rule.');
                }
                $matched = preg_match($cursor ? '/^Mcp\(statamic:([a-z-]+)\)$/D' : '/^mcp__statamic__([a-z-]+)$/D', $rule, $match);
                $name = $match[1] ?? '';
                if ($matched !== 1 || (! isset($renames[$name]) && ! in_array($name, $merged, true))) {
                    $rewritten[] = $rule;

                    continue;
                }
                // An allow of the old mixed router becomes read-only. Its
                // ask/deny restrictions cover both facets. Merged discovery
                // gets no automatic grant from either former subset.
                $targets = $renames[$name] ?? ['statamic-discover'];
                if ($policy === 'allow') {
                    $targets = isset($renames[$name]) ? [$targets[0]] : [];
                }
                $replacements = array_map(fn (string $target): string => $cursor ? "Mcp(statamic:{$target})" : "mcp__statamic__{$target}", $targets);
                array_push($rewritten, ...$replacements);
                $changes[] = "{$policy}: {$rule} → " . ($replacements === [] ? '(removed; approve discovery again)' : implode(', ', $replacements));
            }
            $settings->permissions->{$policy} = $rewritten;
        }

        return $changes;
    }

    private function replace(string $path, string $before, string $after): void
    {
        if (File::get($path) !== $before) {
            throw new \RuntimeException('Settings changed during planning.');
        }
        $backup = fopen($path . '.pre-mcp-v4', 'x');
        if ($backup === false) {
            throw new \RuntimeException('Backup already exists or cannot be created.');
        }
        try {
            if (! chmod($path . '.pre-mcp-v4', 0600) || fwrite($backup, $before) !== strlen($before) || ! fflush($backup)) {
                throw new \RuntimeException('Backup could not be written.');
            }
        } finally {
            fclose($backup);
        }
        if (File::get($path) !== $before) {
            throw new \RuntimeException('Settings changed during backup.');
        }
        File::replace($path, $after, 0600);
    }
}

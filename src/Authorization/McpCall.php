<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Authorization;

use Cboxdk\StatamicMcp\Auth\TokenScope;

/** What a built-in MCP surface is about to do, as McpAuthorizer needs to know it. */
final class McpCall
{
    /**
     * @param  list<TokenScope|string>  $scopes  Every scope the token must hold.
     * @param  array<int, string|array<int, string>>  $permissions  Statamic permissions; a nested list means any one of them.
     * @param  string|null  $handle  The resource the resource policy is keyed on, when known.
     * @param  'read'|'write'  $mode
     * @param  bool  $extraTool  A tool another package registered: missing scopes or permissions fail closed.
     * @param  array{id: string, fingerprint: string}|null  $capability
     */
    public function __construct(
        public readonly string $domain,
        public readonly string $action,
        public readonly array $scopes,
        public readonly array $permissions,
        public readonly ?string $handle,
        public readonly string $mode,
        public readonly bool $extraTool = false,
        public readonly ?array $capability = null,
        public readonly ?string $site = null,
    ) {}
}

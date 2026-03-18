<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Events;

use Statamic\Contracts\Git\ProvidesCommitMessage;
use Statamic\Events\Event;

class McpTokenDeleted extends Event implements ProvidesCommitMessage
{
    public function __construct(
        public readonly string $tokenName,
    ) {}

    public function commitMessage(): string
    {
        return "MCP token deleted: {$this->tokenName}";
    }
}

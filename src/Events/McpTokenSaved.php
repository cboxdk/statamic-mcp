<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Events;

use Cboxdk\StatamicMcp\Storage\Tokens\McpTokenData;
use Statamic\Contracts\Git\ProvidesCommitMessage;
use Statamic\Events\Event;

class McpTokenSaved extends Event implements ProvidesCommitMessage
{
    public function __construct(
        public readonly McpTokenData $token,
    ) {}

    public function commitMessage(): string
    {
        return "MCP token saved: {$this->token->name}";
    }
}

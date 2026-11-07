<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Mcp;

use Cboxdk\StatamicMcp\Mcp\Tools\BaseRouter;

/** Runtime registrations owned by one application container, never by the process. */
final class ExtraToolRegistrations
{
    /** @var array<class-string<BaseRouter>, bool> */
    private array $tools = [];

    /** @param class-string<BaseRouter> $class */
    public function register(string $class, bool $searchable): void
    {
        $this->tools[$class] = $searchable;
    }

    /** @return array<class-string<BaseRouter>, bool> */
    public function all(): array
    {
        return $this->tools;
    }

    public function flush(): void
    {
        $this->tools = [];
    }
}

<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Tests\Fixtures\ExtraTools;

use Cboxdk\StatamicMcp\Mcp\Tools\BaseRouter;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

/**
 * An extra tool that names the intents statamic-system-discover should send it.
 */
#[Name('fixture-seo')]
#[Description('Fixture: a page\'s SEO title and meta description. Actions: get.')]
class KeywordedExtraRouter extends BaseRouter
{
    /**
     * @return list<string>
     */
    public function discoveryKeywords(): array
    {
        return ['seo', 'meta description', 'Canonical', ' ', 'SEO '];
    }

    protected function getDomain(): string
    {
        return 'fixture-seo';
    }

    /**
     * @return array<string, string>
     */
    protected function getActions(): array
    {
        return ['get' => 'Get a page\'s SEO values'];
    }

    /**
     * @return array<string, string>
     */
    protected function getTypes(): array
    {
        return [];
    }

    /**
     * @param  array<string, mixed>  $arguments
     *
     * @return array<string, mixed>
     */
    protected function executeAction(array $arguments): array
    {
        return ['title' => 'Home'];
    }
}

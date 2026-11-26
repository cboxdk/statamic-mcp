<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Tests\Fixtures\ExtraTools;

use Cboxdk\StatamicMcp\Mcp\Tools\BaseRouter;
use Cboxdk\StatamicMcp\Mcp\Tools\RouterAction;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

/**
 * An extra tool in a domain this package has no token scope for.
 */
#[Name('fixture-site-health')]
#[Description('Fixture: report the site health. Actions: get.')]
class UnscopedExtraRouter extends BaseRouter
{
    protected function getDomain(): string
    {
        return 'site-health';
    }

    protected function actions(): array
    {
        return [RouterAction::read('get', 'Get the site health')];
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
        return ['healthy' => true];
    }
}

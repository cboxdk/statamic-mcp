<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Tests\Fixtures\ExtraTools;

use Cboxdk\StatamicMcp\Mcp\Tools\BaseRouter;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

/**
 * An extra tool that names the Statamic permission it needs.
 *
 * Otherwise like SystemScopedExtraRouter: an extra tool in the system domain, so it is gated by system:read.
 */
#[Name('fixture-permissioned-report')]
#[Description('Fixture: report the site health. Actions: get.')]
class PermissionedExtraRouter extends BaseRouter
{
    protected function getDomain(): string
    {
        return 'system';
    }

    /**
     * @return array<string, string>
     */
    protected function getActions(): array
    {
        return ['get' => 'Get the site health'];
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

    /**
     * @param  array<string, mixed>  $arguments
     *
     * @return array<int, string>
     */
    protected function getRequiredPermissions(string $action, array $arguments): array
    {
        return ['access utilities'];
    }
}

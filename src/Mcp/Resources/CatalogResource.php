<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Mcp\Resources;

use Cboxdk\StatamicMcp\Mcp\Resources\Concerns\AuthorizesResourceAccess;
use Cboxdk\StatamicMcp\Mcp\Tools\Routers\BlueprintsRouter;
use Cboxdk\StatamicMcp\Mcp\Tools\Routers\StructuresRouter;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\MimeType;
use Laravel\Mcp\Server\Resource;

#[MimeType('application/json')]
final class CatalogResource extends Resource
{
    use AuthorizesResourceAccess;

    /** @param 'collections'|'sites'|'fieldtypes' $catalog */
    public function __construct(private readonly string $catalog) {}

    public function name(): string
    {
        return 'statamic-' . $this->catalog;
    }

    public function uri(): string
    {
        return 'statamic://' . $this->catalog;
    }

    public function description(): string
    {
        return "Readable {$this->catalog}. If next_cursor is present, continue with the corresponding read tool and the same arguments.";
    }

    protected function domain(): string
    {
        return $this->catalog === 'fieldtypes' ? 'blueprints' : 'structures';
    }

    public function handle(Request $request): Response
    {
        return $this->catalog === 'fieldtypes'
            ? $this->readThroughRouter(new BlueprintsRouter, ['action' => 'fieldtypes'])
            : $this->readThroughRouter(new StructuresRouter, ['action' => 'list', 'resource_type' => $this->catalog === 'collections' ? 'collection' : 'site']);
    }
}

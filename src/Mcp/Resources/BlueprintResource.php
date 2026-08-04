<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Mcp\Resources;

use Cboxdk\StatamicMcp\Mcp\Resources\Concerns\AuthorizesResourceAccess;
use Cboxdk\StatamicMcp\Mcp\Resources\Concerns\LocatesBlueprints;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\MimeType;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Contracts\HasUriTemplate;
use Laravel\Mcp\Server\Resource;
use Laravel\Mcp\Support\UriTemplate;
use Statamic\Fields\Field;

/**
 * One blueprint's field structure, addressable by namespace and handle.
 *
 * Lets a client read the schema it needs to shape a write without spending a
 * tool call — the same data statamic-blueprints get returns, behind the same
 * authorization gates.
 */
#[Name('statamic-blueprint')]
#[Title('Statamic Blueprint')]
#[Description('A single blueprint\'s fields, by namespace and handle. Browse statamic://blueprints for the available URIs.')]
#[MimeType('application/json')]
class BlueprintResource extends Resource implements HasUriTemplate
{
    use AuthorizesResourceAccess;
    use LocatesBlueprints;

    protected function domain(): string
    {
        return 'blueprints';
    }

    public function uriTemplate(): UriTemplate
    {
        return new UriTemplate('statamic://blueprints/{namespace}/{handle}');
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        $namespace = $request->get('namespace');
        $handle = $request->get('handle');

        if (! is_string($namespace) || ! is_string($handle) || $namespace === '' || $handle === '') {
            return Response::error('A blueprint URI must be statamic://blueprints/{namespace}/{handle}.');
        }

        if ($reason = $this->denyReason($handle)) {
            return Response::error("Permission denied: {$reason}");
        }

        $blueprint = $this->findBlueprint($namespace, $handle);

        if ($blueprint === null) {
            return Response::error("Blueprint not found: {$namespace}/{$handle}");
        }

        return Response::json([
            'handle' => $blueprint->handle(),
            'title' => $blueprint->title(),
            'namespace' => $blueprint->namespace(),
            'hidden' => $blueprint->hidden(),
            'fields' => $this->describeFields($blueprint->fields()->all()->all()),
        ]);
    }

    /**
     * @param  array<array-key, mixed>  $fields
     *
     * @return list<array<string, mixed>>
     */
    private function describeFields(array $fields): array
    {
        $described = [];

        foreach ($fields as $field) {
            if (! $field instanceof Field) {
                continue;
            }

            $described[] = [
                'handle' => $field->handle(),
                'type' => $field->type(),
                'display' => $field->display(),
                'required' => $field->isRequired(),
                'validate' => $field->get('validate'),
            ];
        }

        return $described;
    }
}

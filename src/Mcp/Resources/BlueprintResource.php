<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Mcp\Resources;

use Cboxdk\StatamicMcp\Mcp\Resources\Concerns\AuthorizesResourceAccess;
use Cboxdk\StatamicMcp\Mcp\Resources\Concerns\LocatesBlueprints;
use Laravel\Mcp\Enums\CacheScope;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Cacheable;
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
 *
 * Blueprints change when a developer changes them, not during a session, so a
 * short reuse window saves the repeated reads a single turn makes while
 * shaping a write. It is deliberately short rather than generous: this addon
 * can itself edit a blueprint, and an agent holding a stale schema across its
 * own edit is the one failure this hint could cause.
 *
 * Private scope is required, not merely cautious — the body is filtered by the
 * caller's resource policy and Statamic permissions.
 */
#[Name('statamic-blueprint')]
#[Title('Statamic Blueprint')]
#[Description('A single blueprint\'s fields, by namespace and handle. Browse statamic://blueprints for the available URIs.')]
#[MimeType('application/json')]
#[Cacheable(ttlMs: 60_000, scope: CacheScope::Private)]
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
            return $this->refusal('A blueprint URI must be statamic://blueprints/{namespace}/{handle}.', 'INVALID_URI');
        }

        if ($reason = $this->denyReason($handle)) {
            return $this->refusal("Permission denied: {$reason}", 'PERMISSION_DENIED');
        }

        $blueprint = $this->findBlueprint($namespace, $handle);

        if ($blueprint === null) {
            return $this->refusal("Blueprint not found: {$namespace}/{$handle}", 'NOT_FOUND');
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

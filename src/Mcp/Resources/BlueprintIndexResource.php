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
use Laravel\Mcp\Server\Attributes\Uri;
use Laravel\Mcp\Server\Resource;

/**
 * The browsable entry point into the site's schema.
 *
 * Lists each blueprint's addressable URI rather than its fields — a site with
 * many collections would otherwise put its whole schema into the client's
 * context on a single read. Clients read the URI they actually need.
 *
 * The entries are plain data rather than ResourceLink content: the library
 * rejects resource links inside a resource's own body (they are only valid in
 * tool results), so a URI string is what a client gets to follow.
 */
#[Name('statamic-blueprints-index')]
#[Title('Statamic Blueprints')]
#[Description('Index of every readable blueprint on the site, linking to each one.')]
#[Uri('statamic://blueprints')]
#[MimeType('application/json')]
class BlueprintIndexResource extends Resource
{
    use AuthorizesResourceAccess;
    use LocatesBlueprints;

    protected function domain(): string
    {
        return 'blueprints';
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        if ($reason = $this->denyReason()) {
            return Response::error("Permission denied: {$reason}");
        }

        $blueprints = [];

        foreach ($this->allBlueprints() as $blueprint) {
            $handle = $blueprint->handle();
            $namespace = $blueprint->namespace();

            if (! is_string($handle) || $handle === '' || ! is_string($namespace) || $namespace === '') {
                continue;
            }

            // Blueprints the policy hides must not be discoverable either.
            if ($this->denyReason($handle) !== null) {
                continue;
            }

            $blueprints[] = [
                'namespace' => $namespace,
                'handle' => $handle,
                'title' => $blueprint->title(),
                'uri' => "statamic://blueprints/{$namespace}/{$handle}",
            ];
        }

        return Response::json([
            'blueprints' => $blueprints,
            'total' => count($blueprints),
        ]);
    }
}

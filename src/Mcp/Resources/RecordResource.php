<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Mcp\Resources;

use Cboxdk\StatamicMcp\Mcp\Resources\Concerns\AuthorizesResourceAccess;
use Cboxdk\StatamicMcp\Mcp\Support\HandleCompletions;
use Cboxdk\StatamicMcp\Mcp\Tools\Routers\FormsRouter;
use Cboxdk\StatamicMcp\Mcp\Tools\Routers\StructuresRouter;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\MimeType;
use Laravel\Mcp\Server\Completions\CompletionResponse;
use Laravel\Mcp\Server\Contracts\Completable;
use Laravel\Mcp\Server\Contracts\HasUriTemplate;
use Laravel\Mcp\Server\Resource;
use Laravel\Mcp\Support\UriTemplate;

#[MimeType('application/json')]
final class RecordResource extends Resource implements Completable, HasUriTemplate
{
    use AuthorizesResourceAccess;

    /** @param 'collection'|'taxonomy'|'form' $kind */
    public function __construct(private readonly string $kind) {}

    public function name(): string
    {
        return 'statamic-' . $this->kind;
    }

    public function description(): string
    {
        return "Read one {$this->kind} by handle, with the read tool's access checks and field policy.";
    }

    protected function domain(): string
    {
        return $this->kind === 'form' ? 'forms' : 'structures';
    }

    public function uriTemplate(): UriTemplate
    {
        $path = $this->kind === 'taxonomy' ? 'taxonomies' : $this->kind . 's';

        return new UriTemplate('statamic://' . $path . '/{' . $this->kind . '}');
    }

    public function handle(Request $request): Response
    {
        $handle = $request->get($this->kind);
        if (! is_string($handle) || $handle === '') {
            return $this->refusal('The URI requires a resource handle.', 'INVALID_URI');
        }
        // UriTemplate captures are still percent-encoded; decode exactly once.
        $handle = rawurldecode($handle);

        return $this->kind === 'form'
            ? $this->readThroughRouter(new FormsRouter, ['action' => 'get', 'form' => $handle])
            : $this->readThroughRouter(new StructuresRouter, ['action' => 'get', 'resource_type' => $this->kind, 'handle' => $handle]);
    }

    public function complete(string $argument, string $value, array $context): CompletionResponse
    {
        return $argument === $this->kind && config('statamic.mcp.resources.enabled', true)
            ? HandleCompletions::for($argument, $value, $context)
            : CompletionResponse::empty();
    }
}

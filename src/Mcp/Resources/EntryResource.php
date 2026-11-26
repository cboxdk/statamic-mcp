<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Mcp\Resources;

use Cboxdk\StatamicMcp\Mcp\Resources\Concerns\AuthorizesResourceAccess;
use Cboxdk\StatamicMcp\Mcp\Support\HandleCompletions;
use Cboxdk\StatamicMcp\Mcp\Tools\Routers\EntriesRouter;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\MimeType;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Completions\CompletionResponse;
use Laravel\Mcp\Server\Contracts\Completable;
use Laravel\Mcp\Server\Contracts\HasUriTemplate;
use Laravel\Mcp\Server\Resource;
use Laravel\Mcp\Support\UriTemplate;

#[Name('statamic-entry')]
#[Description('Read a saved entry in one collection and site. Uses the same permissions and field filtering as statamic-entries-read get.')]
#[MimeType('application/json')]
final class EntryResource extends Resource implements Completable, HasUriTemplate
{
    use AuthorizesResourceAccess;

    protected function domain(): string
    {
        return 'entries';
    }

    public function uriTemplate(): UriTemplate
    {
        return new UriTemplate('statamic://entries/{collection}/{site}/{id}');
    }

    public function handle(Request $request): Response
    {
        $arguments = ['action' => 'get'];
        foreach (['collection', 'site', 'id'] as $key) {
            $value = $request->get($key);
            if (! is_string($value) || $value === '') {
                return $this->refusal('The URI requires collection, site and id.', 'INVALID_URI');
            }
            $arguments[$key] = rawurldecode($value);
        }

        return $this->readThroughRouter(new EntriesRouter, $arguments);
    }

    public function complete(string $argument, string $value, array $context): CompletionResponse
    {
        return in_array($argument, ['collection', 'site'], true) && config('statamic.mcp.resources.enabled', true)
            ? HandleCompletions::for($argument, $value, $context)
            : CompletionResponse::empty();
    }
}

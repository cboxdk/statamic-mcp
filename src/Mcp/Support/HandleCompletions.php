<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Mcp\Support;

use Cboxdk\StatamicMcp\Mcp\Tools\Routers\BlueprintsRouter;
use Cboxdk\StatamicMcp\Mcp\Tools\Routers\FormsRouter;
use Cboxdk\StatamicMcp\Mcp\Tools\Routers\StructuresRouter;
use Laravel\Mcp\Server\Completions\CompletionResponse;
use Laravel\Mcp\Server\Completions\DirectCompletionResponse;

/** Suggestions are authorized router reads, including every continuation page. */
final class HandleCompletions
{
    /** @param array<string, mixed> $context */
    public static function for(string $argument, string $value, array $context = []): CompletionResponse
    {
        if (in_array($argument, ['collection', 'taxonomy', 'site', 'target_site'], true)) {
            $router = new StructuresRouter;
            $arguments = ['action' => 'list', 'resource_type' => $argument === 'target_site' ? 'site' : $argument, 'limit' => 500];
        } elseif ($argument === 'form') {
            $router = new FormsRouter;
            $arguments = ['action' => 'list', 'limit' => 500];
        } elseif ($argument === 'blueprint' && is_string($context['collection'] ?? null)) {
            $router = new BlueprintsRouter;
            $arguments = ['action' => 'list', 'namespace' => 'collections', 'include_fields' => false, 'limit' => 500];
        } else {
            return CompletionResponse::empty();
        }

        $matches = [];
        do {
            $result = $router->facet('read')->execute($arguments);
            $data = is_array($result['data'] ?? null) ? $result['data'] : [];
            $items = is_array($data['items'] ?? null) ? $data['items'] : [];
            foreach ($items as $item) {
                if ($argument === 'blueprint' && (! is_array($item) || ($item['namespace'] ?? null) !== 'collections.' . $context['collection'])) {
                    continue;
                }
                if (is_array($item) && is_string($item['handle'] ?? null) && str_starts_with(strtolower($item['handle']), strtolower($value))) {
                    $matches[] = $item['handle'];
                }
            }
            $arguments['cursor'] = $data['next_cursor'] ?? null;
        } while (is_string($arguments['cursor']) && count($matches) <= 100);

        return new DirectCompletionResponse(array_slice($matches, 0, 100), count($matches) > 100 || isset($data['next_cursor']));
    }
}

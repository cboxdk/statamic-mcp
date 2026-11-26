<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Mcp\Methods;

use Cboxdk\StatamicMcp\Mcp\Tools\BaseRouter;
use Illuminate\Support\Collection;
use Laravel\Mcp\Server\Methods\ListTools;
use Laravel\Mcp\Server\Pagination\CursorPaginator;
use Laravel\Mcp\Server\ServerContext;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Transport\JsonRpcRequest;
use Laravel\Mcp\Transport\JsonRpcResponse;

/**
 * tools/list showing only the tools this caller can use: the token's scopes
 * and the installation profile decide, as the spec allows. A call to a tool
 * left out still reaches it and is refused with the reason (a missing scope
 * names the scope), so a client can ask for more access instead of reading
 * "tool not found".
 */
final class ListPermittedTools extends ListTools
{
    public function handle(JsonRpcRequest $request, ServerContext $context): JsonRpcResponse
    {
        $request->validate([
            'cursor' => ['nullable', 'string'],
            'per_page' => ['nullable', 'integer:strict', 'min:1'],
        ]);

        $perPage = $request->get('per_page');
        $paginator = new CursorPaginator(
            items: new Collection($this->permitted($context)),
            perPage: $context->perPage(is_int($perPage) ? $perPage : null),
            cursor: $request->cursor(),
        );

        return JsonRpcResponse::result($request->id, $paginator->paginate('tools'));
    }

    /** @return array<int, mixed> */
    private function permitted(ServerContext $context): array
    {
        return $context->tools()
            ->filter(fn (Tool $tool): bool => ! $tool instanceof BaseRouter || $tool->availableToCaller())
            ->values()
            ->all();
    }
}

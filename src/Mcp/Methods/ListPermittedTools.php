<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Mcp\Methods;

use Cboxdk\StatamicMcp\Mcp\Tools\BaseRouter;
use Illuminate\Support\Collection;
use Laravel\Mcp\Enums\ErrorCode;
use Laravel\Mcp\Exceptions\JsonRpcException;
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
        // Checked here rather than with JsonRpcRequest::validate(), which
        // laravel/mcp only gained after 1.1.2, the lowest version we allow.
        $cursor = $request->get('cursor');
        $perPage = $request->get('per_page');

        if (($cursor !== null && ! is_string($cursor)) || ($perPage !== null && (! is_int($perPage) || $perPage < 1))) {
            throw new JsonRpcException(
                'Invalid params: cursor must be a string and per_page a positive integer.',
                ErrorCode::INVALID_PARAMS->value,
                $request->id,
            );
        }

        $paginator = new CursorPaginator(
            items: new Collection($this->permitted($context)),
            perPage: $context->perPage($perPage),
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

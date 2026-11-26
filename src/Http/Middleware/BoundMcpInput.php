<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Http\Middleware;

use Cboxdk\StatamicMcp\Actions\Events\RequestRejected;
use Cboxdk\StatamicMcp\Actions\LifecycleEvents;
use Cboxdk\StatamicMcp\Support\WireInputBudget;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

final class BoundMcpInput
{
    public function __construct(private readonly WireInputBudget $budget, private readonly HandleMcpCors $cors) {}

    /** @param Closure(Request): Response $next */
    public function handle(Request $request, Closure $next): Response
    {
        $path = config('statamic.mcp.web.path', '/mcp/statamic');
        if (! config('statamic.mcp.web.enabled', false) || ! is_string($path)
            || trim(rawurldecode($request->getPathInfo()), '/') !== trim($path, '/')) {
            return $next($request);
        }

        // Runs before global input transformations, which otherwise decode JSON.
        // Keep early failures readable by permitted browser clients too.
        $start = hrtime(true);
        $response = $this->cors->handle($request, fn (Request $request): Response => $this->bounded($request, $next));
        if ($response->getStatusCode() >= 400 && $response->getStatusCode() < 500) {
            $correlation = $request->attributes->get('mcp_correlation_id');
            LifecycleEvents::dispatch(new RequestRejected((string) Str::uuid(),
                is_string($correlation) ? $correlation : (string) Str::uuid(),
                $response->getStatusCode(), (hrtime(true) - $start) / 1_000_000, LifecycleEvents::traceparent()));
        }

        return $response;
    }

    /** @param Closure(Request): Response $next */
    private function bounded(Request $request, Closure $next): Response
    {
        // Never trust Content-Length alone: chunked bodies need the same bound.
        $stream = $request->getContent(true);
        if (! is_resource($stream)) {
            return response()->json(['error' => 'Invalid MCP request body.'], 400);
        }
        $json = stream_get_contents($stream, $this->budget->maxBytes + 1);
        if ($json === false || ! rewind($stream)) {
            return response()->json(['error' => 'Cannot read MCP request body.'], 400);
        }
        try {
            if ($json !== '') {
                $this->budget->check($json);
            }
        } catch (\LengthException) {
            return response()->json(['error' => 'MCP request exceeds its input budget.'], 413);
        } catch (\InvalidArgumentException) {
            return response()->json(['error' => 'Invalid MCP JSON request.'], 400);
        }

        return $next($request);
    }
}

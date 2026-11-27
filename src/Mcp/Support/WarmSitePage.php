<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Mcp\Support;

use GuzzleHttp\Client;
use GuzzleHttp\Psr7\Query;
use GuzzleHttp\Psr7\Request;
use Illuminate\Http\Request as HttpRequest;
use Statamic\Console\Commands\StaticWarmJob;
use Statamic\StaticCaching\Cacher;

/** Statamic's warmer, with pagination jobs kept on the selected worker queue. */
final class WarmSitePage extends StaticWarmJob
{
    public function handle(): void
    {
        $response = (new Client($this->clientConfig))->send($this->request);
        if (! $response->hasHeader('X-Statamic-Pagination')) {
            return;
        }
        [$currentPage, $totalPages, $pageName] = $this->paginationHeader($response);
        if (array_key_exists($pageName, Query::parse($this->request->getUri()->getQuery()))) {
            return;
        }
        $cacher = app(Cacher::class);
        $cachedAs = $cacher->getUrl(HttpRequest::create((string) $this->request->getUri()));
        for ($page = $currentPage; $page <= $totalPages; $page++) {
            $url = (string) $this->request->getUri();
            $url .= (str_contains($url, '?') ? '&' : '?') . http_build_query([$pageName => $page]);
            if ($cacher->getUrl(HttpRequest::create($url)) === $cachedAs) {
                continue;
            }
            self::dispatch(new Request('GET', $url, $this->request->getHeaders()), $this->clientConfig)
                ->onConnection($this->connection)->onQueue($this->queue);
        }
    }
}

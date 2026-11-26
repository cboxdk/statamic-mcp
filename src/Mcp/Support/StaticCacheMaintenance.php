<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Mcp\Support;

use Cboxdk\StatamicMcp\Mcp\DataTransferObjects\ErrorCode;
use Cboxdk\StatamicMcp\Mcp\Exceptions\ToolFailure;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\UriResolver;
use Illuminate\Support\Collection;
use Statamic\Facades\Site;
use Statamic\Sites\Site as SiteObject;
use Statamic\StaticCaching\Cacher;
use Statamic\StaticCaching\Cachers\AbstractCacher;
use Statamic\StaticCaching\Cachers\ApplicationCacher;
use Statamic\StaticCaching\Cachers\FileCacher;
use Statamic\StaticCaching\WarmableUrls;

/** Cache maintenance uses configured site URLs, never arbitrary fetch targets. */
final class StaticCacheMaintenance
{
    public function __construct(private Cacher $cacher) {}

    public function mode(): string
    {
        $mode = config('statamic.mcp.cache.static_after_write');

        return in_array($mode, ['full', 'statamic', 'refresh'], true) ? $mode
            : (config('statamic.mcp.cache.clear_static_after_write', true) ? 'full' : 'statamic');
    }

    /** @return list<string> */
    public function cachedUrls(): array
    {
        $cacher = $this->cacher;
        if (! $cacher instanceof AbstractCacher) {
            return [];
        }
        $urls = [];
        foreach ($cacher->getDomains() as $domain) {
            if (! is_string($domain)) {
                continue;
            }
            foreach ($cacher->getUrls($domain) as $path) {
                if (is_string($path)) {
                    $urls[] = $domain . $path;
                }
            }
        }

        return array_values(array_unique($urls));
    }

    public function alreadyEmptyAfterNativeInvalidation(): bool
    {
        if (config('statamic.static_caching.invalidation.rules') !== 'all'
            || ! $this->cacher instanceof AbstractCacher || $this->cachedUrls() !== []) {
            return false;
        }
        if ($this->cacher instanceof FileCacher) {
            // cache:clear removes URL metadata, but leaves the HTML on disk.
            // An empty URL list alone must never suppress the full clear.
            try {
                foreach ($this->cacher->getCachePaths() as $path) {
                    if (! is_string($path) || ! is_dir($path)) {
                        continue;
                    }
                    $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS));
                    if ($files->valid()) {
                        return false;
                    }
                }

                return true;
            } catch (\UnexpectedValueException) {
                return false;
            }
        }

        return $this->cacher instanceof ApplicationCacher;
    }

    /** @return list<string> */
    public function warmableUrls(): array
    {
        return array_values(array_filter(app(WarmableUrls::class)->all()->all(),
            fn (mixed $url): bool => is_string($url) && $this->isSiteUrl($url)));
    }

    /** @param list<string> $urls */
    public function queueWarm(array $urls): void
    {
        $this->validateUrls($urls);
        $connection = config('statamic.static_caching.warm_queue_connection') ?? config('queue.default');
        if (! is_string($connection) || in_array(config("queue.connections.{$connection}.driver"), [null, 'sync', 'null'], true)) {
            throw new ToolFailure(ErrorCode::Validation, 'Static warming requires an asynchronous statamic.static_caching.warm_queue_connection.', 'cache_type');
        }
        $queue = config('statamic.static_caching.warm_queue');
        foreach (array_unique($urls) as $url) {
            WarmSitePage::dispatch(new Request('GET', $url), ['allow_redirects' => false, 'timeout' => 30])
                ->onConnection($connection)->onQueue(is_string($queue) ? $queue : null);
        }
    }

    /** @param list<string> $urls */
    public function invalidate(array $urls): void
    {
        $this->validateUrls($urls);
        $this->cacher->invalidateUrls($urls);
    }

    /** @param list<string> $urls */
    private function validateUrls(array $urls): void
    {
        foreach ($urls as $url) {
            if (! $this->isSiteUrl($url)) {
                throw new ToolFailure(ErrorCode::Validation, 'Use absolute URLs within configured sites, without wildcards or dot segments.', 'urls');
            }
        }
    }

    public function isSiteUrl(string $url): bool
    {
        $parts = parse_url($url);
        if (! is_array($parts) || ! in_array($parts['scheme'] ?? '', ['http', 'https'], true)
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])
            || preg_match('/[\x00-\x20\\\\*]/', rawurldecode($url))
            || preg_match('~(?:^|/)\.{1,2}(?:/|$)~', rawurldecode($parts['path'] ?? ''))) {
            return false;
        }
        /** @var Collection<string, SiteObject> $sites */
        $sites = Site::all();
        foreach ($sites as $site) {
            // absoluteUrl() uses the request Host for relative sites. The fetch
            // allowlist must instead come entirely from trusted configuration.
            $appUrl = config('app.url');
            if (! is_string($appUrl)) {
                return false;
            }
            $siteUri = new Uri($site->url());
            $base = parse_url($siteUri->getHost() === ''
                ? rtrim($appUrl, '/') . '/' . ltrim($site->url(), '/')
                : (string) UriResolver::resolve(new Uri($appUrl), $siteUri));
            if (! is_array($base)) {
                continue;
            }
            $basePath = rtrim($base['path'] ?? '', '/');
            $path = $parts['path'] ?? '/';
            if ($parts['scheme'] === ($base['scheme'] ?? null)
                && ($parts['host'] ?? null) === ($base['host'] ?? null)
                && ($parts['port'] ?? null) === ($base['port'] ?? null)
                && ($basePath === '' || $path === $basePath || str_starts_with($path, $basePath . '/'))) {
                return true;
            }
        }

        return false;
    }
}

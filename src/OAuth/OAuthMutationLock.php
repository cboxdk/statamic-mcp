<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\OAuth;

use Closure;
use Illuminate\Cache\Repository;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Support\Facades\Cache;
use LogicException;

/** Serialize token issuance and grant changes across HTTP requests. */
final class OAuthMutationLock
{
    /**
     * @template T
     *
     * @param  Closure(): T  $callback
     *
     * @return T
     */
    public function run(Closure $callback): mixed
    {
        /** @var string|null $store */
        $store = config('statamic.mcp.oauth.lock_store');

        // Use a shared, lock-capable cache store on multi-node deployments.
        $cache = Cache::store($store);
        if (! $cache instanceof Repository || ! ($backend = $cache->getStore()) instanceof LockProvider) {
            throw new LogicException('OAuth requires a cache store supporting atomic locks.');
        }

        return $backend->lock('statamic-mcp:oauth-mutations', 120)->block(10, $callback);
    }
}

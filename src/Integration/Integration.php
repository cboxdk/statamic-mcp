<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Integration;

use Cboxdk\StatamicMcp\Actions\Contracts\LifecycleObserver;
use Cboxdk\StatamicMcp\Actions\ObserverRegistry;
use Cboxdk\StatamicMcp\Actions\ScopeRegistry;
use Cboxdk\StatamicMcp\Mcp\Support\FieldtypeExtensions;
use Cboxdk\StatamicMcp\Mcp\Tools\BaseRouter;
use Statamic\Fields\Field;

final readonly class Integration
{
    public function __construct(public string $package)
    {
        if (! preg_match('/^[a-z0-9][a-z0-9-]*\/[a-z0-9][a-z0-9-]*$/D', $package)) {
            throw new \InvalidArgumentException('An integration needs its Composer package name.');
        }
    }

    /** @param list<string> $permissions All are required; empty means super users only. */
    public function scope(string $name, string $label, array $permissions = []): self
    {
        app(ScopeRegistry::class)->register($this->package, $name, $label, $permissions);

        return $this;
    }

    /** @param class-string<BaseRouter> $class
     * @param  list<string>  $approvals  Additional actions requiring approval by default.
     */
    public function router(string $class, int $version = 1, array $approvals = []): self
    {
        app(IntegrationRegistry::class)->register($this->package, $class, $version, $approvals);

        return $this;
    }

    /** @param class-string<LifecycleObserver> $class */
    public function observer(string $class): self
    {
        app(ObserverRegistry::class)->register($class);

        return $this;
    }

    /** @param callable(Field): (array<string, mixed>|null) $spec
     * @param  callable(mixed, Field, string): mixed  $sanitizer
     */
    public function fieldtype(string $handle, callable $spec, callable $sanitizer): self
    {
        app(IntegrationRegistry::class)->claimFieldtype($this->package, $handle);
        FieldtypeExtensions::spec($handle, $spec);
        FieldtypeExtensions::sanitizer($handle, $sanitizer);

        return $this;
    }
}

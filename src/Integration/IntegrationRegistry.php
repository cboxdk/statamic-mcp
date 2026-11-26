<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Integration;

use Cboxdk\StatamicMcp\Actions\CapabilityRegistry;
use Cboxdk\StatamicMcp\Actions\SchemaValidator;
use Cboxdk\StatamicMcp\Mcp\ExtraTools;
use Cboxdk\StatamicMcp\Mcp\Servers\StatamicMcpServer;
use Cboxdk\StatamicMcp\Mcp\Support\FieldtypeExtensions;
use Cboxdk\StatamicMcp\Mcp\Tools\BaseRouter;

/** One application's integration ownership and immutable consent contracts. */
final class IntegrationRegistry
{
    /** @var array<class-string<BaseRouter>, array{package: string, domain: string, version: int, approvals: list<string>, schema: array<string, mixed>, actions: array<string, array{id: string, fingerprint: string}>}> */
    private array $routers = [];

    /** @var array<string, string> */
    private array $fieldtypes = [];

    /** @var array<class-string<BaseRouter>, bool> */
    private array $legacyTools = [];

    private bool $frozen = false;

    /** @param class-string<BaseRouter> $class
     * @param  list<string>  $approvals
     */
    public function register(string $package, string $class, int $version, array $approvals): void
    {
        if ($this->frozen || $version < 1 || ! is_subclass_of($class, BaseRouter::class)) {
            throw new \LogicException('Invalid or late integration router registration.');
        }
        $class = (new \ReflectionClass($class))->getName();
        if ((new \ReflectionMethod($class, 'authorizeAction'))->getDeclaringClass()->getName() === BaseRouter::class) {
            throw new \LogicException('Integration routers must implement their resource authorization policy.');
        }
        $router = app($class);
        $domain = $router->domain();
        if ($domain === 'guidance' || ! preg_match('/^[a-z][a-z0-9-]{0,31}$/D', $domain)) {
            throw new \LogicException('Invalid integration domain.');
        }
        foreach ([...StatamicMcpServer::CORE_TOOLS, ...StatamicMcpServer::SEARCHABLE_TOOLS, ...array_keys(ExtraTools::all())] as $existing) {
            $tool = app($existing);
            if ($existing === $class || $tool->name() === $router->name()
                || ($tool instanceof BaseRouter && $tool->domain() === $domain)) {
                throw new \LogicException('An integration cannot replace an existing router, tool name or domain.');
            }
        }
        $declarations = $router->declaredActions();
        if ($declarations === [] || array_diff($approvals, array_map(fn ($action): string => $action->name, $declarations)) !== []) {
            throw new \LogicException('An integration must declare its actions and approval defaults.');
        }
        $schema = $router->toArray()['inputSchema'];
        // Normalize JsonSchema objects into a local JSON schema before checking it.
        $schema = json_decode(json_encode($schema, JSON_THROW_ON_ERROR), true, 32, JSON_THROW_ON_ERROR);
        if (! is_array($schema)) {
            throw new \LogicException('Invalid router input schema.');
        }
        $schema = array_filter($schema, is_string(...), ARRAY_FILTER_USE_KEY);
        SchemaValidator::checkSchema($schema);
        $actions = [];
        $bindings = [];
        foreach ($declarations as $action) {
            if (! $action->declaresArguments() || $action->scopes === [] || $action->outputSchema === null) {
                throw new \LogicException('Integration actions must declare arguments, owned scopes and an output schema.');
            }
            SchemaValidator::checkSchema($action->outputSchema);
            $id = $package . '/' . str_replace('-', '_', $domain) . '_' . $action->name;
            $fingerprint = hash('sha256', json_encode([
                $class, $version, $action, $schema, $approvals,
            ], JSON_THROW_ON_ERROR));
            $bindings[$id] = ['fingerprint' => $fingerprint, 'scopes' => $action->scopes];
            $actions[$action->name] = ['id' => $id, 'fingerprint' => $fingerprint];
        }
        app(CapabilityRegistry::class)->registerBindings($package, $bindings);
        $this->routers[$class] = ['package' => $package, 'domain' => $domain, 'version' => $version, 'approvals' => $approvals, 'schema' => $schema, 'actions' => $actions];
    }

    /** @return array<string, mixed>|null */
    public function inputSchema(string $class): ?array
    {
        return $this->routers[ltrim($class, '\\')]['schema'] ?? null;
    }

    /** @return array{id: string, fingerprint: string}|null */
    public function binding(string $class, string $action): ?array
    {
        return $this->routers[ltrim($class, '\\')]['actions'][$action] ?? null;
    }

    public function package(string $class): ?string
    {
        return $this->routers[ltrim($class, '\\')]['package'] ?? null;
    }

    public function version(string $class): int
    {
        return $this->routers[ltrim($class, '\\')]['version'] ?? 1;
    }

    /** @return array<class-string<BaseRouter>, bool> */
    public function tools(): array
    {
        return [...$this->legacyTools, ...array_fill_keys(array_keys($this->routers), true)];
    }

    /** Compatibility registration: never confers custom scopes or consent.
     * @param  class-string<BaseRouter>  $class
     */
    public function registerLegacy(string $class, bool $searchable): void
    {
        $tool = app($class);
        foreach ($this->routers as $registered => $router) {
            if ($registered === $class || $router['domain'] === $tool->domain() || app($registered)->name() === $tool->name()) {
                throw new \LogicException('This router, domain or tool name is already owned by an integration.');
            }
        }
        $this->legacyTools[$class] = $searchable;
    }

    /** Forget legacy registrations in tests without removing integration consent. */
    public function flushLegacy(): void
    {
        $this->legacyTools = [];
    }

    /** @return list<string> */
    public function approvals(string $domain): array
    {
        foreach ($this->routers as $router) {
            if ($router['domain'] === $domain) {
                return $router['approvals'];
            }
        }

        return [];
    }

    public function claimFieldtype(string $package, string $handle): void
    {
        if ($this->frozen || ! preg_match('/^[a-z][a-z0-9_]*$/D', $handle)
            || isset($this->fieldtypes[$handle]) || in_array($handle, FieldtypeExtensions::registered(), true)) {
            throw new \LogicException('Invalid, duplicate or late integration fieldtype registration.');
        }
        $this->fieldtypes[$handle] = $package;
    }

    public function freeze(): void
    {
        $this->frozen = true;
    }
}

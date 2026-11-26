<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Mcp\Tools;

use Cboxdk\StatamicMcp\Actions\ActionEffect;

/**
 * One action a router offers, declared once.
 *
 * The effect is the single answer to "what does this action do": whether it
 * writes (resource policy mode, idempotency), which token scopes it needs
 * (`{domain}:read`, the domain's write scope, plus `{domain}:publish` or
 * `{domain}:delete` where the domain has them) and what an approval or
 * operation record says it does. Nothing classifies actions by name any more,
 * so an action a router does not declare does not exist.
 *
 * The arguments are the single answer to "what does this action read":
 * `needs` must be present, `optional` may be. Together they decide which
 * arguments each tool's schema offers, what the action's description lists,
 * and which calls are refused before anything runs (a missing need, or an
 * argument the action does not take). An entry `a|b` means one of them, as in
 * `id|email`. Leaving both null declares nothing: the action then accepts
 * every argument in the router's schema, which only extension tools rely on.
 */
final readonly class RouterAction
{
    /**
     * @param  list<string>  $types  The resource types it applies to; empty means every type, or a router without types.
     * @param  list<string>|null  $needs
     * @param  list<string>|null  $optional
     * @param  list<string>  $scopes  Integration scopes; core routers derive theirs from the domain.
     * @param  array<string, mixed>|null  $outputSchema  Integration action's success data, without the envelope.
     */
    public function __construct(
        public string $name,
        public ActionEffect $effect,
        public string $description,
        public array $types = [],
        public ?array $needs = null,
        public ?array $optional = null,
        public array $scopes = [],
        public ?array $outputSchema = null,
    ) {
        if (preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $name) !== 1 || trim($description) === '') {
            throw new \InvalidArgumentException("Invalid router action '{$name}'.");
        }
        foreach ([...($needs ?? []), ...($optional ?? [])] as $argument) {
            if (preg_match('/^[a-z][a-z0-9_]*(\|[a-z][a-z0-9_]*)*$/D', $argument) !== 1) {
                throw new \InvalidArgumentException("Invalid argument '{$argument}' on router action '{$name}'.");
            }
        }
    }

    /**
     * @param  list<string>  $types
     * @param  list<string>|null  $needs
     * @param  list<string>|null  $optional
     */
    public static function read(string $name, string $description, array $types = [], ?array $needs = null, ?array $optional = null): self
    {
        return new self($name, ActionEffect::Read, $description, $types, $needs, $optional);
    }

    /**
     * @param  list<string>  $types
     * @param  list<string>|null  $needs
     * @param  list<string>|null  $optional
     */
    public static function edit(string $name, string $description, array $types = [], ?array $needs = null, ?array $optional = null): self
    {
        return new self($name, ActionEffect::Edit, $description, $types, $needs, $optional);
    }

    /**
     * @param  list<string>  $types
     * @param  list<string>|null  $needs
     * @param  list<string>|null  $optional
     */
    public static function publish(string $name, string $description, array $types = [], ?array $needs = null, ?array $optional = null): self
    {
        return new self($name, ActionEffect::Publish, $description, $types, $needs, $optional);
    }

    /**
     * @param  list<string>  $types
     * @param  list<string>|null  $needs
     * @param  list<string>|null  $optional
     */
    public static function delete(string $name, string $description, array $types = [], ?array $needs = null, ?array $optional = null): self
    {
        return new self($name, ActionEffect::Delete, $description, $types, $needs, $optional);
    }

    /**
     * @param  list<string>  $types
     * @param  list<string>|null  $needs
     * @param  list<string>|null  $optional
     */
    public static function admin(string $name, string $description, array $types = [], ?array $needs = null, ?array $optional = null): self
    {
        return new self($name, ActionEffect::Admin, $description, $types, $needs, $optional);
    }

    public function writes(): bool
    {
        return $this->effect !== ActionEffect::Read;
    }

    /** Bind an integration action to explicit scopes and its result contract.
     * @param  list<string>  $scopes
     * @param  array<string, mixed>  $outputSchema
     */
    public function withContract(array $scopes, array $outputSchema): self
    {
        return new self($this->name, $this->effect, $this->description, $this->types, $this->needs, $this->optional, $scopes, $outputSchema);
    }

    public function appliesTo(?string $type): bool
    {
        return $this->types === [] || $type === null || in_array($type, $this->types, true);
    }

    public function declaresArguments(): bool
    {
        return $this->needs !== null || $this->optional !== null;
    }

    /**
     * Every argument name the action reads, alternatives split.
     *
     * @return list<string>
     */
    public function argumentNames(): array
    {
        $names = [];
        foreach ([...($this->needs ?? []), ...($this->optional ?? [])] as $entry) {
            array_push($names, ...explode('|', $entry));
        }

        return array_values(array_unique($names));
    }

    /**
     * The needs the arguments leave unmet, each as written (`id|email`).
     * An argument that is null or an empty string counts as absent.
     *
     * @param  array<string, mixed>  $arguments
     *
     * @return list<string>
     */
    public function missingNeeds(array $arguments): array
    {
        return array_values(array_filter($this->needs ?? [], function (string $need) use ($arguments): bool {
            foreach (explode('|', $need) as $name) {
                $value = $arguments[$name] ?? null;
                if ($value !== null && $value !== '' && $value !== []) {
                    return false;
                }
            }

            return true;
        }));
    }

    /** The action and its arguments in one line, as a tool description lists it. */
    public function summary(): string
    {
        $line = rtrim($this->description, '. ') . '.';
        $needs = array_map(fn (string $need): string => str_replace('|', ' or ', $need), $this->needs ?? []);
        if ($needs !== []) {
            $line .= ' Needs ' . implode(', ', $needs) . '.';
        }
        if (($this->optional ?? []) !== []) {
            $line .= ' Optional: ' . implode(', ', array_map(fn (string $o): string => str_replace('|', ' or ', $o), $this->optional ?? [])) . '.';
        }

        return $line;
    }
}

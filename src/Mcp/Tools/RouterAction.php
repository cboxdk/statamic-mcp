<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Mcp\Tools;

use Cboxdk\StatamicMcp\Actions\ActionEffect;

/**
 * One action a router offers, declared once.
 *
 * The effect is the single answer to "what does this action do": whether it
 * writes (resource policy mode, idempotency, unknown-argument refusal), which
 * token scopes it needs (`{domain}:read`, the domain's write scope, plus
 * `{domain}:publish` or `{domain}:delete` where the domain has them) and what
 * an approval or operation record says it does. Nothing classifies actions by
 * name any more, so an action a router does not declare does not exist.
 */
final readonly class RouterAction
{
    /**
     * @param  list<string>  $types  The resource types it applies to; empty means every type, or a router without types.
     */
    public function __construct(
        public string $name,
        public ActionEffect $effect,
        public string $description,
        public array $types = [],
    ) {
        if (preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $name) !== 1 || trim($description) === '') {
            throw new \InvalidArgumentException("Invalid router action '{$name}'.");
        }
    }

    /** @param  list<string>  $types */
    public static function read(string $name, string $description, array $types = []): self
    {
        return new self($name, ActionEffect::Read, $description, $types);
    }

    /** @param  list<string>  $types */
    public static function edit(string $name, string $description, array $types = []): self
    {
        return new self($name, ActionEffect::Edit, $description, $types);
    }

    /** @param  list<string>  $types */
    public static function publish(string $name, string $description, array $types = []): self
    {
        return new self($name, ActionEffect::Publish, $description, $types);
    }

    /** @param  list<string>  $types */
    public static function delete(string $name, string $description, array $types = []): self
    {
        return new self($name, ActionEffect::Delete, $description, $types);
    }

    /** @param  list<string>  $types */
    public static function admin(string $name, string $description, array $types = []): self
    {
        return new self($name, ActionEffect::Admin, $description, $types);
    }

    public function writes(): bool
    {
        return $this->effect !== ActionEffect::Read;
    }

    public function appliesTo(?string $type): bool
    {
        return $this->types === [] || $type === null || in_array($type, $this->types, true);
    }
}

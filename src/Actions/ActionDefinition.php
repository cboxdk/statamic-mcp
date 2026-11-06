<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Actions;

use Cboxdk\StatamicMcp\Actions\Contracts\ActionHandler;
use Cboxdk\StatamicMcp\Actions\Contracts\ActionPolicy;

final readonly class ActionDefinition
{
    /** @param class-string<ActionHandler> $handler
     * @param  class-string<ActionPolicy>  $policy
     * @param  list<string>  $scopes
     * @param  array<string, mixed>  $inputSchema
     * @param  array<string, mixed>  $outputSchema
     */
    public function __construct(
        public string $package,
        public string $name,
        public int $version,
        public string $description,
        public ActionEffect $effect,
        public array $scopes,
        public array $inputSchema,
        public array $outputSchema,
        public string $handler,
        public string $policy,
    ) {
        if (! preg_match('/^[a-z0-9][a-z0-9-]*\/[a-z0-9][a-z0-9-]*$/D', $package)
            || ! preg_match('/^[a-z][a-z0-9_-]{0,63}$/D', $name)
            || $version < 1 || trim($description) === '' || strlen($description) > 2000
            || $scopes === [] || count(array_unique($scopes)) !== count($scopes)
            || ! is_subclass_of($handler, ActionHandler::class)
            || ! is_subclass_of($policy, ActionPolicy::class)) {
            throw new \InvalidArgumentException('Invalid action definition.');
        }
        foreach ($scopes as $scope) {
            if (! preg_match('/^[a-z][a-z0-9._-]*:[a-z][a-z0-9._-]*$/D', $scope)) {
                throw new \InvalidArgumentException('Actions require explicit named scopes.');
            }
        }
        SchemaValidator::checkSchema($inputSchema);
        SchemaValidator::checkSchema($outputSchema);
    }

    public function id(): string
    {
        return $this->package . '/' . $this->name;
    }

    /** A grant binds the full definition, not just a reusable scope label. */
    public function fingerprint(): string
    {
        return hash('sha256', json_encode([
            $this->id(), $this->version, $this->effect->value, $this->scopes,
            $this->inputSchema, $this->outputSchema, $this->handler, $this->policy,
        ], JSON_THROW_ON_ERROR));
    }
}

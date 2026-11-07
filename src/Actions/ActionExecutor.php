<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Actions;

use Cboxdk\StatamicMcp\Actions\Contracts\ActionHandler;
use Cboxdk\StatamicMcp\Actions\Contracts\ActionPolicy;
use Cboxdk\StatamicMcp\Actions\Contracts\ActorScope;
use Cboxdk\StatamicMcp\Actions\Contracts\Authorizer;
use Cboxdk\StatamicMcp\Actions\Operations\Operation;
use Cboxdk\StatamicMcp\Actions\Operations\OperationCoordinator;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Str;
use Statamic\Auth\PermissionCache;
use Statamic\Facades\Blink;

/** Internal V01 prototype. Write admission requires the V04 operation store. */
final class ActionExecutor
{
    public function __construct(
        private readonly CapabilityRegistry $registry,
        private readonly Authorizer $authorizer,
        private readonly SchemaValidator $schemas,
        private readonly LifecycleObservers $observers,
        private readonly Container $container,
        private readonly OperationCoordinator $operations,
        private readonly ActorScope $actors,
    ) {}

    /** Authorize and validate intent without invoking its handler.
     * @param  array<string, mixed>  $input
     */
    public function prepare(string $id, array $input, ExecutionContext $context): Operation
    {
        return $this->actors->run($context, fn (): Operation => $this->prepareAsActor($id, $input, $context));
    }

    /** @param array<string, mixed> $input */
    private function prepareAsActor(string $id, array $input, ExecutionContext $context): Operation
    {
        Blink::flush();
        $this->container->make(PermissionCache::class)->clear();
        $action = $this->registry->get($id);
        $this->authorizer->authorize($action, $context);
        $context->checkDeadline();
        $this->schemas->validateInput($input, $action->inputSchema);
        $policy = $this->container->make($action->policy);
        if (! $policy instanceof ActionPolicy) {
            throw new \LogicException('Invalid action policy binding.');
        }
        $policy->authorize($input, $context);
        $this->authorizer->authorize($action, $context);

        return $this->operations->prepare($action, $input, $context);
    }

    /** @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function execute(string $id, array $input, ExecutionContext $context): array
    {
        $entered = false;
        $start = hrtime(true);
        try {
            return $this->actors->run($context, function () use ($id, $input, $context, &$entered): array {
                $entered = true;

                return $this->executeAsActor($id, $input, $context);
            });
        } catch (\Throwable $error) {
            if (! $entered) {
                $this->emit($this->registry->get($id), $context, 'denied', 'none', $start, (string) Str::uuid());
            }
            throw $error;
        }
    }

    /** @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    private function executeAsActor(string $id, array $input, ExecutionContext $context): array
    {
        Blink::flush();
        $this->container->make(PermissionCache::class)->clear();
        $action = $this->registry->get($id);
        $executionId = (string) Str::uuid();
        $started = false;
        $start = hrtime(true);
        try {
            $this->authorizer->authorize($action, $context);
            $context->checkDeadline();
            $this->schemas->validateInput($input, $action->inputSchema);
            $policy = $this->container->make($action->policy);
            if (! $policy instanceof ActionPolicy) {
                throw new \LogicException('Invalid action policy binding.');
            }
            $policy->authorize($input, $context);
            $this->authorizer->authorize($action, $context);
            $context->checkDeadline();
            $handler = $this->container->make($action->handler);
            if (! $handler instanceof ActionHandler) {
                throw new \LogicException('Invalid action handler binding.');
            }
            if (! in_array($action->effect, [ActionEffect::Read, ActionEffect::Edit], true)) {
                throw new \LogicException('This prototype does not admit actions requiring approval.');
            }
            $execute = function () use ($handler, $policy, $input, $context, $action, $start, $executionId, &$started): array {
                $this->authorizer->authorize($action, $context);
                $policy->authorize($input, $context);
                $context->checkDeadline();
                $started = true;
                $this->emit($action, $context, 'started', 'none', $start, $executionId);
                $result = $handler->execute($input, $context);
                $result = $policy->filterResult($input, $result, $context);
                $this->schemas->validate($result, $action->outputSchema);

                return $result;
            };
            if ($action->effect === ActionEffect::Read) {
                $result = $execute();
                $this->emit($action, $context, 'completed', 'none', $start, $executionId);

                return $result;
            }
            $result = $this->operations->execute($action, $input, $context, $execute);
            $visible = $policy->filterResult($input, $result->data, $context);
            $this->schemas->validate($visible, $action->outputSchema);
            $this->emit($action, $context, $result->replayed ? 'replayed' : 'completed', $result->replayed ? 'none' : 'committed', $start, $executionId);

            return $visible;
        } catch (\Throwable $error) {
            $this->emit($action, $context, $started ? 'failed' : 'denied', $started && $action->effect !== ActionEffect::Read ? 'unknown' : 'none', $start, $executionId);
            throw $error;
        }
    }

    private function emit(ActionDefinition $action, ExecutionContext $context, string $outcome, string $effects, int $start, string $executionId): void
    {
        $eventClass = match ($outcome) {
            'started' => Events\ExecutionStarted::class,
            'completed' => Events\ExecutionCompleted::class,
            'denied' => Events\ExecutionDenied::class,
            'failed' => Events\ExecutionFailed::class,
            'replayed' => Events\ExecutionReplayed::class,
            default => throw new \LogicException('Unknown execution outcome.'),
        };
        $this->observers->dispatch(new $eventClass(
            (string) Str::uuid(), $context->operationId, $action->id(), $action->version,
            $context->transport, $outcome, $effects, (hrtime(true) - $start) / 1_000_000,
            gmdate('Y-m-d\TH:i:s\Z'), executionId: $executionId,
        ));
    }
}

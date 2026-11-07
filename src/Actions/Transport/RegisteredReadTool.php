<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Actions\Transport;

use Cboxdk\StatamicMcp\Actions\ActionDefinition;
use Cboxdk\StatamicMcp\Actions\ActionEffect;
use Cboxdk\StatamicMcp\Actions\ActionExecutor;
use Cboxdk\StatamicMcp\Actions\Contracts\Authorizer;
use Illuminate\Auth\Access\AuthorizationException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tool;
use Statamic\Facades\Site;

/** Read-only vertical slice. Writes are not exposed before versioning and approvals. */
final class RegisteredReadTool extends Tool
{
    public function __construct(private readonly ActionDefinition $action)
    {
        if ($action->effect !== ActionEffect::Read) {
            throw new \InvalidArgumentException('This adapter only exposes read actions.');
        }
    }

    public function name(): string
    {
        return $this->action->toolName();
    }

    public function description(): string
    {
        return $this->action->description;
    }

    public function shouldRegister(): bool
    {
        // Discovery must not hide a tool merely because the actor's permitted
        // site differs from the installation default. Calls recheck their site.
        $sites = Site::all();
        if (! is_iterable($sites)) {
            return false;
        }
        foreach ($sites as $site) {
            if (! $site instanceof \Statamic\Sites\Site) {
                continue;
            }
            try {
                app(Authorizer::class)->authorize($this->action, app(ContextFactory::class)->create($site->handle()));

                return true;
            } catch (\Throwable) {
                // Try the remaining configured sites without disclosing the denial.
            }
        }

        return false;
    }

    public function toArray(): array
    {
        return [
            'name' => $this->name(), 'description' => $this->description(),
            'inputSchema' => ['type' => 'object', 'properties' => [
                'input' => $this->action->inputSchema,
                'site' => ['type' => 'string', 'description' => 'Site to access. Defaults to the installation default.'],
            ], 'required' => ['input'], 'additionalProperties' => false],
            'outputSchema' => $this->action->outputSchema,
            'annotations' => ['readOnlyHint' => true, 'destructiveHint' => false, 'idempotentHint' => true, 'openWorldHint' => true],
        ];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        try {
            $envelope = $request->all();
            if (array_diff(array_keys($envelope), ['input', 'site']) !== []
                || ! is_array($envelope['input'] ?? null)
                || ($envelope['input'] !== [] && array_is_list($envelope['input']))
                || (array_key_exists('site', $envelope) && ! is_string($envelope['site']))) {
                throw new \InvalidArgumentException;
            }
            $context = app(ContextFactory::class)->create($envelope['site'] ?? null);
            $input = [];
            foreach ($envelope['input'] as $key => $value) {
                if (! is_string($key)) {
                    throw new \InvalidArgumentException;
                }
                $input[$key] = $value;
            }

            return Response::structured(app(ActionExecutor::class)->execute($this->action->id(), $input, $context));
        } catch (AuthorizationException) {
            return Response::error('Action access denied.');
        } catch (\InvalidArgumentException|\JsonException) {
            return Response::error('Invalid action input or result.');
        } catch (\Throwable) {
            return Response::error('Action could not be completed.');
        }
    }
}

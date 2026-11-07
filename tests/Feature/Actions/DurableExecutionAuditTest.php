<?php

declare(strict_types=1);

use Cboxdk\StatamicMcp\Actions\ActionDefinition;
use Cboxdk\StatamicMcp\Actions\ActionEffect;
use Cboxdk\StatamicMcp\Actions\ActionExecutor;
use Cboxdk\StatamicMcp\Actions\CapabilityRegistry;
use Cboxdk\StatamicMcp\Actions\Contracts\ActionHandler;
use Cboxdk\StatamicMcp\Actions\Contracts\ActionPolicy;
use Cboxdk\StatamicMcp\Actions\Contracts\ActorScope;
use Cboxdk\StatamicMcp\Actions\Contracts\Authorizer;
use Cboxdk\StatamicMcp\Actions\ExecutionAudit;
use Cboxdk\StatamicMcp\Actions\ExecutionContext;
use Cboxdk\StatamicMcp\Actions\LifecycleObservers;
use Cboxdk\StatamicMcp\Actions\Operations\FileOperationStore;
use Cboxdk\StatamicMcp\Actions\Operations\OperationCoordinator;
use Cboxdk\StatamicMcp\Actions\Operations\OperationState;
use Cboxdk\StatamicMcp\Actions\SchemaValidator;
use Cboxdk\StatamicMcp\Actions\ScopeRegistry;
use Cboxdk\StatamicMcp\Contracts\AuditStore;
use Cboxdk\StatamicMcp\Contracts\DurableAuditStore;
use Illuminate\Contracts\Encryption\Encrypter;
use Illuminate\Support\Facades\File;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

beforeEach(function () {
    $this->directory = sys_get_temp_dir() . '/mcp-execution-audit-' . bin2hex(random_bytes(8));
    $this->store = new FileOperationStore($this->directory);
    $scopes = new ScopeRegistry;
    $scopes->register('fixture/audit', 'audit:edit', 'Edit');
    $registry = new CapabilityRegistry($scopes);
    foreach (['edit' => ActionEffect::Edit, 'read' => ActionEffect::Read] as $name => $effect) {
        $registry->register(new ActionDefinition('fixture/audit', $name, 1, 'Audit test action.', $effect,
            ['audit:edit'], ['type' => 'object'], ['type' => 'object'], AuditHandler::class, AuditPolicy::class));
    }
    $registry->freeze();
    $authorizer = Mockery::mock(Authorizer::class);
    $authorizer->shouldReceive('authorize');
    $actors = Mockery::mock(ActorScope::class);
    $actors->shouldReceive('run')->andReturnUsing(fn (ExecutionContext $context, Closure $callback): mixed => $callback());
    $this->executor = function (AuditStore $audit, ?LoggerInterface $logger = null) use ($registry, $authorizer, $actors): ActionExecutor {
        return new ActionExecutor($registry, $authorizer, app(SchemaValidator::class), new LifecycleObservers(new NullLogger),
            $this->app, new OperationCoordinator($this->store, app(Encrypter::class)), $actors,
            new ExecutionAudit($audit, $logger ?? new NullLogger));
    };
    $this->context = new ExecutionContext('actor', 'grant', 'default', 'operator', 'http', 'prepare', hrtime(true) + 10_000_000_000);
    AuditHandler::$calls = 0;
});

afterEach(function () {
    File::deleteDirectory($this->directory);
});

it('fails closed before the handler when durable audit is unavailable or unsupported', function (bool $supported) {
    $audit = Mockery::mock($supported ? DurableAuditStore::class : AuditStore::class);
    if ($supported) {
        $audit->shouldReceive('writeDurable')->andThrow(new RuntimeException('Audit disk full.'));
    }
    $executor = ($this->executor)($audit);
    $operation = $executor->prepare('fixture/audit/edit', [], $this->context);
    $context = $this->context->withOperationId($operation->id);
    expect(fn () => $executor->execute('fixture/audit/edit', [], $context))->toThrow(Exception::class);
    expect(AuditHandler::$calls)->toBe(0)->and($this->store->find($operation->id)->state)->toBe(OperationState::FailedNoEffect);
    // A failed admission is terminal; it cannot race a later retry into a write.
    expect(fn () => $executor->execute('fixture/audit/edit', [], $context))->toThrow(RuntimeException::class);
    expect(AuditHandler::$calls)->toBe(0);
})->with([true, false]);

it('retains a successful outcome when terminal audit and fallback logging fail', function () {
    config()->set('statamic.mcp.security.audit_logging', false);
    $records = [];
    $audit = Mockery::mock(DurableAuditStore::class);
    $audit->shouldReceive('writeDurable')->andReturnUsing(function (array $entry) use (&$records): void {
        $records[] = $entry;
        if ($entry['status'] !== 'admitted') {
            throw new RuntimeException('Audit failed after native write.');
        }
        expect(AuditHandler::$calls)->toBe(0);
    });
    $logger = Mockery::mock(LoggerInterface::class);
    $logger->shouldReceive('warning')->andThrow(new RuntimeException('Logger unavailable.'));
    $executor = ($this->executor)($audit, $logger);
    $input = ['content' => 'private-content-not-for-audit'];
    $operation = $executor->prepare('fixture/audit/edit', $input, $this->context);
    $context = $this->context->withOperationId($operation->id);
    expect($executor->execute('fixture/audit/edit', $input, $context))->toBe(['ok' => true]);
    expect($this->store->find($operation->id)->state)->toBe(OperationState::Succeeded);
    expect($executor->execute('fixture/audit/edit', $input, $context))->toBe(['ok' => true]);
    expect(AuditHandler::$calls)->toBe(1)->and(array_column($records, 'status'))->toBe(['admitted', 'completed', 'replayed']);
    expect(json_encode($records, JSON_THROW_ON_ERROR))->not->toContain('private-content-not-for-audit');
    expect($records[0]['metadata']['operation_id'])->toBe($operation->id);
});

it('does not require durable write admission for a read action', function () {
    $executor = ($this->executor)(Mockery::mock(AuditStore::class));
    expect($executor->execute('fixture/audit/read', [], $this->context))->toBe(['ok' => true]);
    expect(AuditHandler::$calls)->toBe(1)->and($this->store->reconcileCapacity())->toBe(0);
});

it('keeps committed effects explicit if current output filtering fails after persistence', function () {
    $records = [];
    $audit = Mockery::mock(DurableAuditStore::class);
    $audit->shouldReceive('writeDurable')->andReturnUsing(function (array $entry) use (&$records): void {
        $records[] = $entry;
    });
    $policy = new class implements ActionPolicy
    {
        private int $filters = 0;

        public function authorize(array $input, ExecutionContext $context): void {}

        public function filterResult(array $input, array $result, ExecutionContext $context): array
        {
            if (++$this->filters === 2) {
                throw new RuntimeException('Current field visibility unavailable.');
            }

            return $result;
        }
    };
    $this->app->instance(AuditPolicy::class, $policy);
    $executor = ($this->executor)($audit);
    $operation = $executor->prepare('fixture/audit/edit', [], $this->context);
    $context = $this->context->withOperationId($operation->id);
    expect(fn () => $executor->execute('fixture/audit/edit', [], $context))->toThrow(RuntimeException::class);
    expect($this->store->find($operation->id)->state)->toBe(OperationState::Succeeded)
        ->and($records[1]['status'])->toBe('failed')->and($records[1]['metadata']['effects'])->toBe('committed');
    expect($executor->execute('fixture/audit/edit', [], $context))->toBe(['ok' => true]);
    expect(AuditHandler::$calls)->toBe(1)->and($records[2]['metadata']['effects'])->toBe('none');
});

final class AuditHandler implements ActionHandler
{
    public static int $calls = 0;

    public function execute(array $input, ExecutionContext $context): array
    {
        self::$calls++;

        return ['ok' => true];
    }
}

final class AuditPolicy implements ActionPolicy
{
    public function authorize(array $input, ExecutionContext $context): void {}

    public function filterResult(array $input, array $result, ExecutionContext $context): array
    {
        return $result;
    }
}

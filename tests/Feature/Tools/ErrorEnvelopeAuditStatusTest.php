<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Tests\Feature\Tools;

use Cboxdk\StatamicMcp\Contracts\AuditStore;
use Cboxdk\StatamicMcp\Mcp\Tools\BaseStatamicTool;
use Cboxdk\StatamicMcp\Storage\Audit\FileAuditStore;
use Cboxdk\StatamicMcp\Tests\TestCase;
use Illuminate\Contracts\JsonSchema\JsonSchema as JsonSchemaContract;

/**
 * Routers report a refused write — blueprint validation, a missing entry, a
 * denied resource — as an error envelope rather than by throwing. The audit
 * log took every non-throwing call as a success, so a save rejected with
 * "The Entry field must be an array" was recorded as `status: success`,
 * `level: info`, and the refusal only showed in response_summary. Filtering
 * the activity log on errors missed every one of them.
 */
class ErrorEnvelopeAuditStatusTest extends TestCase
{
    private string $auditPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->auditPath = storage_path('logs/mcp-envelope-test-' . bin2hex(random_bytes(4)) . '.log');

        config(['statamic.mcp.security.audit_logging' => true]);

        $this->app->singleton(AuditStore::class, fn (): FileAuditStore => new FileAuditStore($this->auditPath));
    }

    protected function tearDown(): void
    {
        if (file_exists($this->auditPath)) {
            unlink($this->auditPath);
        }

        parent::tearDown();
    }

    public function test_an_error_envelope_is_logged_as_an_error(): void
    {
        $tool = new EnvelopeTool(fn (BaseStatamicTool $tool): array => $tool->failWith(
            'Field validation failed: seo_canonical_entry: The Entry field must be an array.'
        ));

        $result = $tool->execute(['action' => 'update']);

        $this->assertFalse($result['success']);

        $entry = $this->lastAuditEntry();
        $this->assertSame('error', $entry['status']);
        $this->assertSame('error', $entry['level']);
        $this->assertSame('test-envelope-tool.update: error', $entry['message']);
        $this->assertStringContainsString('The Entry field must be an array', $entry['response_summary']);
    }

    public function test_a_success_envelope_is_still_logged_as_a_success(): void
    {
        $tool = new EnvelopeTool(fn (): array => ['updated' => true]);

        $result = $tool->execute(['action' => 'update']);

        $this->assertTrue($result['success']);

        $entry = $this->lastAuditEntry();
        $this->assertSame('success', $entry['status']);
        $this->assertSame('info', $entry['level']);
        $this->assertSame('test-envelope-tool.update: success', $entry['message']);
    }

    /**
     * @return array<string, mixed>
     */
    private function lastAuditEntry(): array
    {
        $lines = array_values(array_filter(explode("\n", (string) file_get_contents($this->auditPath))));

        $this->assertNotEmpty($lines, 'Expected an audit entry to be written');

        $decoded = json_decode((string) end($lines), true);

        $this->assertIsArray($decoded);

        return $decoded;
    }
}

/**
 * Minimal tool whose executeInternal returns whatever the test hands it, so
 * the audit path can be probed with a real error envelope and without a
 * router or fieldtype.
 */
class EnvelopeTool extends BaseStatamicTool
{
    /** @param \Closure(BaseStatamicTool): array<string, mixed> $result */
    public function __construct(private \Closure $result) {}

    public function name(): string
    {
        return 'test-envelope-tool';
    }

    public function description(): string
    {
        return 'Test tool that returns a prepared result.';
    }

    protected function defineSchema(JsonSchemaContract $schema): array
    {
        return [];
    }

    /**
     * @return array<string, mixed>
     */
    public function failWith(string $message): array
    {
        return $this->createErrorResponse($message)->toArray();
    }

    /**
     * @param  array<string, mixed>  $arguments
     *
     * @return array<string, mixed>
     */
    protected function executeInternal(array $arguments): array
    {
        return ($this->result)($this);
    }
}

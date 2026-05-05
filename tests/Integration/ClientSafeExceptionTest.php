<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Tests\Integration;

use Cboxdk\StatamicMcp\Mcp\Exceptions\FieldFormatException;
use Cboxdk\StatamicMcp\Mcp\Tools\BaseStatamicTool;
use Cboxdk\StatamicMcp\Tests\TestCase;
use Illuminate\Contracts\JsonSchema\JsonSchema as JsonSchemaContract;

/**
 * Verifies that BaseStatamicTool's production error sanitization correctly
 * distinguishes between curated, client-safe exception messages and arbitrary
 * runtime errors.
 *
 * This is the production-environment behaviour the existing integration tests
 * cannot cover, because they run in 'testing' where raw messages are always
 * passed through.
 */
class ClientSafeExceptionTest extends TestCase
{
    public function test_field_format_exception_message_reaches_client_in_production(): void
    {
        $this->app['env'] = 'production';

        $tool = new ThrowingTool(
            new FieldFormatException('Field [page_builder.0] expects replicator data as an array, received string.'),
        );

        $result = $tool->execute([]);

        $this->assertFalse($result['success']);
        $error = $result['error'] ?? ($result['errors'][0] ?? '');
        $this->assertIsString($error);
        $this->assertStringContainsString(
            'Field [page_builder.0] expects replicator data as an array',
            $error,
            'FieldFormatException message must reach the client in production'
        );
    }

    public function test_unrelated_runtime_exception_is_genericised_in_production(): void
    {
        $this->app['env'] = 'production';

        $tool = new ThrowingTool(
            new \RuntimeException('Internal database path /var/www/storage/secret-cred-cache exposed'),
        );

        $result = $tool->execute([]);

        $this->assertFalse($result['success']);
        $error = $result['error'] ?? ($result['errors'][0] ?? '');
        $this->assertIsString($error);
        $this->assertStringNotContainsString('Internal database path', $error);
        $this->assertStringNotContainsString('secret-cred-cache', $error);
        $this->assertStringContainsString('An error occurred', $error);
    }

    public function test_unrelated_type_error_is_genericised_in_production(): void
    {
        $this->app['env'] = 'production';

        $tool = new ThrowingTool(
            new \TypeError('Argument #1 must be of type string, array given, called in /var/www/vendor/statamic/cms/src/Fieldtypes/Bard.php on line 234'),
        );

        $result = $tool->execute([]);

        $this->assertFalse($result['success']);
        $error = $result['error'] ?? ($result['errors'][0] ?? '');
        $this->assertIsString($error);
        $this->assertStringNotContainsString('vendor/statamic', $error);
        $this->assertStringContainsString('An error occurred', $error);
    }

    public function test_field_format_exception_message_also_reaches_client_in_local(): void
    {
        $this->app['env'] = 'local';

        $tool = new ThrowingTool(new FieldFormatException('field [foo] expects bard data'));

        $result = $tool->execute([]);

        $error = $result['error'] ?? ($result['errors'][0] ?? '');
        $this->assertStringContainsString('field [foo] expects bard data', is_string($error) ? $error : '');
    }
}

/**
 * Minimal BaseStatamicTool subclass that throws a configurable exception
 * from executeInternal so tests can probe the error-handling pipeline
 * without depending on a router or fieldtype.
 */
class ThrowingTool extends BaseStatamicTool
{
    public function __construct(private \Throwable $toThrow) {}

    public function name(): string
    {
        return 'test-throwing-tool';
    }

    public function description(): string
    {
        return 'Test tool that throws.';
    }

    protected function defineSchema(JsonSchemaContract $schema): array
    {
        return [];
    }

    /**
     * @param  array<string, mixed>  $arguments
     *
     * @return array<string, mixed>
     */
    protected function executeInternal(array $arguments): array
    {
        throw $this->toThrow;
    }
}

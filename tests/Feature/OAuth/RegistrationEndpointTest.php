<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Tests\Feature\OAuth;

use Cboxdk\StatamicMcp\Tests\TestCase;

class RegistrationEndpointTest extends TestCase
{
    public function test_successful_client_registration(): void
    {
        $response = $this->postJson('/mcp/oauth/register', [
            'client_name' => 'Test MCP Client',
            'redirect_uris' => ['https://example.com/callback'],
        ]);

        $response->assertCreated();

        $data = $response->json();

        $this->assertArrayHasKey('client_id', $data);
        $this->assertArrayHasKey('client_name', $data);
        $this->assertArrayHasKey('redirect_uris', $data);
        $this->assertArrayHasKey('client_id_issued_at', $data);
        $this->assertSame('Test MCP Client', $data['client_name']);
        $this->assertSame(['https://example.com/callback'], $data['redirect_uris']);
        $this->assertIsInt($data['client_id_issued_at']);
        $this->assertStringStartsWith('mcp_', $data['client_id']);
    }

    public function test_registration_fails_without_client_name(): void
    {
        $response = $this->postJson('/mcp/oauth/register', [
            'redirect_uris' => ['https://example.com/callback'],
        ]);

        $response->assertStatus(400);
        $response->assertJson([
            'error' => 'invalid_client_metadata',
        ]);
    }

    public function test_registration_fails_with_empty_client_name(): void
    {
        $response = $this->postJson('/mcp/oauth/register', [
            'client_name' => '',
            'redirect_uris' => ['https://example.com/callback'],
        ]);

        $response->assertStatus(400);
        $response->assertJson([
            'error' => 'invalid_client_metadata',
        ]);
    }

    public function test_registration_fails_without_redirect_uris(): void
    {
        $response = $this->postJson('/mcp/oauth/register', [
            'client_name' => 'Test Client',
        ]);

        $response->assertStatus(400);
        $response->assertJson([
            'error' => 'invalid_client_metadata',
        ]);
    }

    public function test_registration_fails_with_empty_redirect_uris(): void
    {
        $response = $this->postJson('/mcp/oauth/register', [
            'client_name' => 'Test Client',
            'redirect_uris' => [],
        ]);

        $response->assertStatus(400);
        $response->assertJson([
            'error' => 'invalid_client_metadata',
        ]);
    }

    public function test_registration_fails_with_http_non_localhost_redirect_uri(): void
    {
        $response = $this->postJson('/mcp/oauth/register', [
            'client_name' => 'Test Client',
            'redirect_uris' => ['http://example.com/callback'],
        ]);

        $response->assertStatus(400);
        $response->assertJson([
            'error' => 'invalid_request',
        ]);
        $this->assertStringContains('Invalid redirect URI', $response->json('error_description'));
    }

    public function test_registration_allows_http_localhost_redirect_uri(): void
    {
        $response = $this->postJson('/mcp/oauth/register', [
            'client_name' => 'Local Client',
            'redirect_uris' => ['http://localhost:3000/callback'],
        ]);

        $response->assertCreated();
        $this->assertSame('Local Client', $response->json('client_name'));
    }

    /**
     * Assert that a string contains a substring.
     */
    private function assertStringContains(string $needle, string $haystack): void
    {
        $this->assertTrue(
            str_contains($haystack, $needle),
            "Failed asserting that '{$haystack}' contains '{$needle}'",
        );
    }
}

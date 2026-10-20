<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Tests\Feature\OAuth;

use Cboxdk\StatamicMcp\OAuth\Contracts\OAuthDriver;
use Cboxdk\StatamicMcp\OAuth\OAuthClient;
use Cboxdk\StatamicMcp\Tests\TestCase;
use Illuminate\Testing\TestResponse;
use Statamic\Facades\User;

/**
 * The consent screen must show where the code is delivered and resist framing,
 * so a victim cannot be tricked into approving a grant to an attacker's host.
 */
class ConsentScreenDisclosureTest extends TestCase
{
    private const CODE_CHALLENGE = 'E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM';

    private OAuthClient $client;

    private string $redirectUri = 'https://desk.example.com/callback';

    protected function setUp(): void
    {
        parent::setUp();
        config(['statamic.editions.pro' => true]);

        $user = User::make()->id('user-1')->email('user@example.com')->makeSuper();
        $user->save();
        $this->actingAs($user, 'web');

        /** @var OAuthDriver $driver */
        $driver = $this->app->make(OAuthDriver::class);
        $this->client = $driver->registerClient('Desk client', [$this->redirectUri]);
    }

    private function authorize(): TestResponse
    {
        $cpRoute = trim((string) config('statamic.cp.route', 'cp'), '/');

        return $this->get("/{$cpRoute}/mcp/oauth/authorize?" . http_build_query([
            'response_type' => 'code',
            'client_id' => $this->client->clientId,
            'redirect_uri' => $this->redirectUri,
            'code_challenge' => self::CODE_CHALLENGE,
            'code_challenge_method' => 'S256',
            'state' => 'st',
            'scope' => 'entries:read',
        ]));
    }

    public function test_it_shows_the_redirect_host_and_marks_a_dcr_client_unverified(): void
    {
        $response = $this->authorize()->assertOk();

        $response->assertSee('desk.example.com');
        $response->assertSee('registered itself', false);
    }

    public function test_the_consent_screen_cannot_be_framed(): void
    {
        $response = $this->authorize()->assertOk();

        $response->assertHeader('X-Frame-Options', 'DENY');
        $this->assertStringContainsString("frame-ancestors 'none'", (string) $response->headers->get('Content-Security-Policy'));
    }
}

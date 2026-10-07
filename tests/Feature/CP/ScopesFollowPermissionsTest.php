<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Tests\Feature\CP;

use Cboxdk\StatamicMcp\Auth\TokenScope;
use Cboxdk\StatamicMcp\Auth\TokenService;
use Cboxdk\StatamicMcp\OAuth\Contracts\OAuthDriver;
use Cboxdk\StatamicMcp\OAuth\OAuthClient;
use Cboxdk\StatamicMcp\Tests\TestCase;
use Inertia\Testing\AssertableInertia;
use Statamic\Contracts\Auth\User as UserContract;
use Statamic\Facades\Collection;
use Statamic\Facades\Role;
use Statamic\Facades\User;

/**
 * An editor is offered, and may hold, only the scopes their Statamic
 * permissions let them exercise: in the dashboard's picker, when a token is
 * created or edited, and on the OAuth consent screen.
 */
class ScopesFollowPermissionsTest extends TestCase
{
    /** RFC 7636 appendix B pair. */
    private const CODE_VERIFIER = 'dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk';

    private const CODE_CHALLENGE = 'E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM';

    private UserContract $editor;

    private OAuthClient $client;

    private string $redirectUri = 'https://example.com/callback';

    protected function setUp(): void
    {
        parent::setUp();

        config(['statamic.editions.pro' => true]);

        Collection::make('blog')->title('Blog')->save();

        Role::make()->handle('editor')->title('Editor')->permissions([
            'access cp', 'view blog entries', 'edit blog entries',
            'view mcp dashboard', 'create mcp tokens', 'revoke mcp tokens',
        ])->save();

        $this->editor = User::make()->id('editor-1')->email('editor@example.com');
        $this->editor->assignRole('editor');
        $this->editor->save();

        /** @var OAuthDriver $driver */
        $driver = $this->app->make(OAuthDriver::class);
        $this->client = $driver->registerClient('Desk client', [$this->redirectUri]);
    }

    private function authorizeUrl(): string
    {
        /** @var string $cpRoute */
        $cpRoute = config('statamic.cp.route', 'cp');

        return '/' . trim($cpRoute, '/') . '/mcp/oauth/authorize';
    }

    /**
     * @return array<string, string>
     */
    private function oauthParams(string $scope): array
    {
        return [
            'response_type' => 'code',
            'client_id' => $this->client->clientId,
            'redirect_uri' => $this->redirectUri,
            'code_challenge' => self::CODE_CHALLENGE,
            'code_challenge_method' => 'S256',
            'state' => 'st',
            'scope' => $scope,
        ];
    }

    public function test_the_dashboard_offers_an_editor_only_their_scopes(): void
    {
        $response = $this->actingAs($this->editor)
            ->get(cp_route('statamic-mcp.dashboard'))
            ->assertOk();

        // @phpstan-ignore-next-line (assertInertia is a TestResponse macro registered by Inertia)
        $response->assertInertia(fn (AssertableInertia $page) => $page
            ->where('availableScopes', fn ($scopes) => collect($scopes)->pluck('value')->all() === [
                'content:read', 'content:write', 'entries:read', 'entries:write',
            ])
        );
    }

    public function test_a_super_admin_keeps_the_full_picker(): void
    {
        $admin = User::make()->id('admin-1')->email('admin@example.com');
        $admin->makeSuper();
        $admin->save();

        $response = $this->actingAs($admin)->get(cp_route('statamic-mcp.dashboard'))->assertOk();

        // @phpstan-ignore-next-line
        $response->assertInertia(fn (AssertableInertia $page) => $page
            ->where('availableScopes', fn ($scopes) => count($scopes) === count(TokenScope::cases()))
        );
    }

    public function test_creating_a_token_refuses_a_scope_the_editor_cannot_exercise(): void
    {
        $this->actingAs($this->editor)
            ->postJson(cp_route('statamic-mcp.tokens.store'), [
                'name' => 'Desk',
                'scopes' => ['entries:read', 'users:write'],
            ])
            ->assertStatus(422)
            ->assertJsonFragment(['message' => 'These scopes are not available to this user: users:write. Available scopes: content:read, content:write, entries:read, entries:write']);

        $this->actingAs($this->editor)
            ->postJson(cp_route('statamic-mcp.tokens.store'), [
                'name' => 'Desk',
                'scopes' => ['entries:read', 'entries:write'],
            ])
            ->assertStatus(201);
    }

    public function test_editing_a_token_is_capped_by_its_owner_not_by_the_admin(): void
    {
        /** @var TokenService $tokens */
        $tokens = $this->app->make(TokenService::class);
        $token = $tokens->createToken('editor-1', 'Desk', [TokenScope::EntriesRead])['model'];

        $admin = User::make()->id('admin-2')->email('admin2@example.com');
        $admin->makeSuper();
        $admin->save();

        $this->actingAs($admin)
            ->putJson(cp_route('statamic-mcp.tokens.update', ['token' => $token->id]), ['scopes' => ['users:write']])
            ->assertStatus(422);

        $this->actingAs($admin)
            ->putJson(cp_route('statamic-mcp.tokens.update', ['token' => $token->id]), ['scopes' => ['entries:write']])
            ->assertOk();
    }

    public function test_the_consent_screen_shows_an_editor_only_the_scopes_they_can_use(): void
    {
        $response = $this->actingAs($this->editor, 'web')
            ->get($this->authorizeUrl() . '?' . http_build_query($this->oauthParams('entries:read users:write *')))
            ->assertOk();

        $response->assertSee('Read Entries');
        $response->assertDontSee('Write Users');
        $response->assertDontSee('Full Access');
    }

    public function test_a_client_asking_only_for_scopes_the_editor_lacks_is_refused(): void
    {
        $response = $this->actingAs($this->editor, 'web')
            ->get($this->authorizeUrl() . '?' . http_build_query($this->oauthParams('users:write')));

        $response->assertRedirect();
        $this->assertStringContainsString('error=invalid_scope', $response->headers->get('Location', ''));
    }

    public function test_approval_cannot_grant_more_than_was_offered(): void
    {
        $approve = $this->actingAs($this->editor, 'web')
            ->post($this->authorizeUrl(), [
                'decision' => 'approve',
                'client_id' => $this->client->clientId,
                'redirect_uri' => $this->redirectUri,
                'state' => 'st',
                'code_challenge' => self::CODE_CHALLENGE,
                'code_challenge_method' => 'S256',
                'scope' => 'entries:read users:write *',
                'scopes' => ['*', 'users:write', 'entries:read'],
            ]);

        $approve->assertRedirect();
        parse_str((string) parse_url($approve->headers->get('Location', ''), PHP_URL_QUERY), $query);
        $this->assertArrayHasKey('code', $query);

        $exchange = $this->post('/mcp/oauth/token', [
            'grant_type' => 'authorization_code',
            'code' => $query['code'],
            'redirect_uri' => $this->redirectUri,
            'client_id' => $this->client->clientId,
            'code_verifier' => self::CODE_VERIFIER,
        ])->assertOk();

        $this->assertSame('entries:read', $exchange->json('scope'));

        /** @var TokenService $tokens */
        $tokens = $this->app->make(TokenService::class);
        $issued = $tokens->validateToken((string) $exchange->json('access_token'));

        $this->assertNotNull($issued);
        $this->assertSame(['entries:read'], $issued->scopes);
    }

    public function test_a_token_minted_before_the_cap_can_still_be_edited(): void
    {
        /** @var TokenService $tokens */
        $tokens = $this->app->make(TokenService::class);
        $token = $tokens->createToken('editor-1', 'Legacy', [TokenScope::FullAccess])['model'];

        // The edit form resends every scope the token holds.
        $this->actingAs($this->editor)
            ->putJson(cp_route('statamic-mcp.tokens.update', ['token' => $token->id]), [
                'name' => 'Renamed',
                'scopes' => ['*'],
            ])
            ->assertOk();

        // Adding a scope the editor cannot hold is still refused.
        $this->actingAs($this->editor)
            ->putJson(cp_route('statamic-mcp.tokens.update', ['token' => $token->id]), [
                'scopes' => ['*', 'users:write'],
            ])
            ->assertStatus(422)
            ->assertJsonFragment(['message' => 'These scopes are not available to this user: users:write. Available scopes: content:read, content:write, entries:read, entries:write']);
    }

    public function test_a_client_asking_for_everything_is_offered_the_editors_reach(): void
    {
        $response = $this->actingAs($this->editor, 'web')
            ->get($this->authorizeUrl() . '?' . http_build_query($this->oauthParams('*')))
            ->assertOk();

        $response->assertSee('Read Entries');
        $response->assertSee('Write Entries');
        $response->assertDontSee('Full Access');
        $response->assertDontSee('Write Users');
    }

    public function test_approving_a_wildcard_grants_the_editors_reach_not_more(): void
    {
        $approve = $this->actingAs($this->editor, 'web')
            ->post($this->authorizeUrl(), [
                'decision' => 'approve',
                'client_id' => $this->client->clientId,
                'redirect_uri' => $this->redirectUri,
                'state' => 'st',
                'code_challenge' => self::CODE_CHALLENGE,
                'code_challenge_method' => 'S256',
                'scope' => '*',
            ]);

        $approve->assertRedirect();
        parse_str((string) parse_url($approve->headers->get('Location', ''), PHP_URL_QUERY), $query);
        $this->assertArrayHasKey('code', $query);

        $exchange = $this->post('/mcp/oauth/token', [
            'grant_type' => 'authorization_code',
            'code' => $query['code'],
            'redirect_uri' => $this->redirectUri,
            'client_id' => $this->client->clientId,
            'code_verifier' => self::CODE_VERIFIER,
        ])->assertOk();

        $this->assertSame('content:read content:write entries:read entries:write', $exchange->json('scope'));
    }
}

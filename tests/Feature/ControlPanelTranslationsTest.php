<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Tests\Feature;

use Cboxdk\StatamicMcp\Auth\TokenScope;
use Cboxdk\StatamicMcp\OAuth\Contracts\OAuthDriver;
use Cboxdk\StatamicMcp\Tests\Concerns\CreatesAuthenticatedUser;
use Cboxdk\StatamicMcp\Tests\TestCase;
use Illuminate\Support\Arr;
use Statamic\Extensions\Translation\Translator;
use Statamic\Facades\Permission;

class ControlPanelTranslationsTest extends TestCase
{
    use CreatesAuthenticatedUser;

    public function test_english_and_danish_catalogs_have_identical_keys_and_placeholders(): void
    {
        $english = $this->catalog('en');
        $danish = $this->catalog('da');

        $this->assertNotEmpty($english);
        $this->assertSame(array_keys($english), array_keys($danish));

        foreach ($english as $key => $line) {
            $this->assertIsString($line, $key);
            $this->assertIsString($danish[$key], $key);
            $this->assertNotSame('', trim($line), $key);
            $this->assertNotSame('', trim($danish[$key]), $key);
            // A scope such as entries:publish is not a replacement token.
            preg_match_all('/(?<![\w]):[a-z][a-z0-9_]*/i', $line, $original);
            preg_match_all('/(?<![\w]):[a-z][a-z0-9_]*/i', $danish[$key], $translated);
            $this->assertEqualsCanonicalizing($original[0], $translated[0], $key);
        }
    }

    public function test_native_addon_registration_serves_the_same_catalog_to_php_and_javascript(): void
    {
        $translator = app('translator');
        $this->assertInstanceOf(Translator::class, $translator);

        foreach (['en', 'da'] as $locale) {
            app()->setLocale($locale);
            $javascript = $translator->toJson();
            foreach ($this->catalog($locale) as $key => $line) {
                $this->assertSame($line, __($key), $key);
                $this->assertSame($line, $javascript[$key] ?? null, $key);
            }
        }

        app()->setLocale('fr');
        $translator->setFallback('en');
        $this->assertSame('Create Token', __('statamic-mcp::cp.create_token'));
    }

    public function test_every_literal_ui_translation_reference_exists_in_the_catalog(): void
    {
        $catalog = $this->catalog('en');
        $root = dirname(__DIR__, 2);
        $files = [
            ...glob($root . '/resources/js/components/*.vue'),
            ...glob($root . '/resources/js/pages/*.vue'),
            ...glob($root . '/resources/js/composables/*.js'),
            ...glob($root . '/src/Http/Controllers/CP/*.php'),
            $root . '/resources/views/oauth/error.blade.php',
            $root . '/src/Http/Controllers/OAuth/AuthorizeController.php',
            $root . '/src/OAuth/ConsentOffer.php',
            $root . '/src/OAuth/ResourceIndicator.php',
            $root . '/src/Approvals/Approvals.php',
            $root . '/src/Auth/TokenService.php',
            $root . '/src/Authorization/PersonalTokenConsent.php',
            $root . '/src/Auth/ScopeAvailability.php',
            $root . '/src/Services/ClientConfigGenerator.php',
            $root . '/src/ServiceProvider.php',
        ];

        foreach ($files as $file) {
            preg_match_all('/[\'\"](statamic-mcp::(?:cp|messages|scopes)\.[a-zA-Z0-9_.-]+)[\'\"]/', file_get_contents($file), $matches);
            foreach ($matches[1] as $key) {
                $this->assertArrayHasKey($key, $catalog, $file . ': ' . $key);
            }
        }

        // The dynamic keys used by the scope picker and consent groups.
        foreach (TokenScope::cases() as $scope) {
            foreach (['labels', 'descriptions'] as $kind) {
                $this->assertArrayHasKey('statamic-mcp::scopes.' . $kind . '.' . $scope->name, $catalog);
            }
            $this->assertArrayHasKey('statamic-mcp::cp.domains.' . $scope->group(), $catalog);
            if ($scope !== TokenScope::FullAccess) {
                $this->assertArrayHasKey('statamic-mcp::cp.domains.' . explode(':', $scope->value)[0], $catalog);
            }
        }
        foreach (['none', 'read', 'edit', 'edit_publish', 'edit_delete', 'edit_publish_delete', 'admin', 'read_delete'] as $level) {
            $this->assertArrayHasKey('statamic-mcp::cp.levels.' . $level, $catalog);
        }
        foreach (['bearer', 'oauth', 'stdio'] as $mode) {
            $this->assertArrayHasKey('statamic-mcp::cp.connection_modes.' . $mode, $catalog);
        }
        foreach (['success', 'error', 'failed', 'validation_error', 'timeout', 'warning'] as $status) {
            $this->assertArrayHasKey('statamic-mcp::cp.statuses.' . $status, $catalog);
        }
    }

    public function test_dashboard_uses_the_selected_statamic_locale_for_server_supplied_labels(): void
    {
        $user = $this->createSuperAdmin();
        $user->setPreference('locale', 'da')->save();
        $this->actingAs($user);

        $props = $this->get(cp_route('statamic-mcp.dashboard'))->assertOk()->viewData('page')['props'];
        $scopes = array_column($props['availableScopes'], 'label', 'value');
        $this->assertSame('Læs indlæg', $scopes['entries:read']);
        $this->assertSame('Anden', $props['clients']['generic']['name']);
        $this->assertSame('Adgang til MCP-dashboard og opsætning af forbindelser', Permission::get('view mcp dashboard')->description());
    }

    public function test_consent_localizes_without_the_authenticated_control_panel_layout(): void
    {
        config(['statamic.editions.pro' => true]);
        $user = $this->createSuperAdmin();
        $user->setPreference('locale', 'da')->save();
        $this->actingAs($user);

        $client = app(OAuthDriver::class)->registerClient('Desktop', ['https://client.example/callback']);
        $response = $this->get(cp_route('statamic-mcp.oauth.authorize') . '?' . http_build_query([
            'response_type' => 'code',
            'resource' => 'http://localhost/mcp/statamic',
            'client_id' => $client->clientId,
            'redirect_uri' => 'https://client.example/callback',
            'code_challenge' => 'E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM',
            'code_challenge_method' => 'S256',
            'state' => 'translation-test',
            'scope' => 'entries:read',
        ]))->assertOk();

        $props = $response->viewData('page')['props'];
        $this->assertSame('Læs indlæg', $props['scopes'][0]['label']);
        $this->assertSame('Se indlæg på tværs af samlinger.', $props['scopes'][0]['description']);
    }

    public function test_descriptions_use_escaped_slots_not_the_native_html_text_prop(): void
    {
        $root = dirname(__DIR__, 2) . '/resources/js';
        foreach ([...glob($root . '/pages/*.vue'), ...glob($root . '/components/*.vue')] as $file) {
            $source = file_get_contents($file);
            // Description.text uses v-html. Even translated replacements must
            // go through a slot, never that prop or a new raw HTML directive.
            $this->assertDoesNotMatchRegularExpression('/<ui-description\b[^>]*\s(?::|v-bind:)?text\s*=/s', $source, $file);
            $this->assertStringNotContainsString('v-html', $source, $file);
        }
    }

    /** @return array<string, string> */
    private function catalog(string $locale): array
    {
        $lines = [];
        foreach (glob(dirname(__DIR__, 2) . '/resources/lang/' . $locale . '/*.php') as $file) {
            foreach (Arr::dot(require $file) as $key => $value) {
                $lines['statamic-mcp::' . basename($file, '.php') . '.' . $key] = $value;
            }
        }
        ksort($lines);

        return $lines;
    }
}

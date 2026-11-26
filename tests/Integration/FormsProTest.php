<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Tests\Integration;

use Cboxdk\StatamicMcp\Actions\CapabilityRegistry;
use Cboxdk\StatamicMcp\Auth\TokenService;
use Cboxdk\StatamicMcp\Mcp\Tools\Routers\BlueprintsRouter;
use Cboxdk\StatamicMcp\Mcp\Tools\Routers\EntriesRouter;
use Cboxdk\StatamicMcp\Tests\TestCase;
use Statamic\Addons\Manifest;
use Statamic\Console\Processes\Composer;
use Statamic\Facades\Blueprint;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;
use Statamic\Facades\Form;
use Statamic\Facades\Role;
use Statamic\Facades\Stache;
use Statamic\Facades\User;
use Statamic\FormsPro\ServiceProvider;

class FormsProTest extends TestCase
{
    private string $token;

    protected function getPackageProviders($app)
    {
        return [...parent::getPackageProviders($app), ...(class_exists(ServiceProvider::class) ? [ServiceProvider::class] : [])];
    }

    protected function getEnvironmentSetUp($app)
    {
        parent::getEnvironmentSetUp($app);
        if (class_exists(ServiceProvider::class)) {
            // Testbench's skeleton has no project lockfile. Use the installed
            // dependency graph, just as Statamic does in a real application.
            \Facades\Statamic\Console\Processes\Composer::swap(
                Composer::create(dirname(__DIR__, 2) . '/')
            );
            $app->make(Manifest::class)->manifest['statamic/forms-pro'] = [
                'id' => 'statamic/forms-pro', 'slug' => null, 'version' => '1.0.1',
                'namespace' => 'Statamic\\FormsPro', 'autoload' => 'src', 'provider' => ServiceProvider::class,
            ];
        }
    }

    protected function setUp(): void
    {
        parent::setUp();
        if (! class_exists(ServiceProvider::class)) {
            $this->markTestSkipped('Install statamic/forms-pro to run its integration suite.');
        }
        Role::make()->handle('form-editor')->permissions(['access cp', 'edit contact form'])->save();
        $user = User::make()->email('forms-pro@example.test')->assignRole('form-editor');
        $user->save();
        $scopes = ['forms-pro:read', 'forms-pro:write', 'forms:read', 'forms:write'];
        $this->token = app(TokenService::class)->createToken($user->id(), 'Forms Pro', $scopes,
            capabilities: app(CapabilityRegistry::class)->forScopes($scopes))['token'];
        Form::make('contact')->title('Contact')->formFields(['pages' => [
            ['id' => 'start', 'sections' => [['fields' => [['handle' => 'choice', 'field' => ['type' => 'short_answer']]]]],
                'rules' => [['conditions' => [['field' => 'choice', 'operator' => 'equals', 'value' => 'skip']], 'destination' => 'finish']]],
            ['id' => 'middle', 'sections' => []],
            ['id' => 'finish', 'sections' => []],
        ]])->save();
        Form::make('private')->title('Private')->save();
    }

    private function callAction(string $action, array $arguments = [], string $domain = 'forms-pro')
    {
        $name = 'statamic-' . $domain . '-' . ($action === 'update' ? 'write' : 'read');

        return $this->postJson('/mcp/statamic', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call',
            'params' => ['name' => $name, 'arguments' => ['action' => $action, 'form' => 'contact', ...$arguments],
                '_meta' => ['io.modelcontextprotocol/protocolVersion' => '2026-07-28', 'io.modelcontextprotocol/clientCapabilities' => []]]],
            ['Authorization' => 'Bearer ' . $this->token, 'MCP-Protocol-Version' => '2026-07-28', 'Mcp-Method' => 'tools/call', 'Mcp-Name' => $name]);
    }

    public function test_native_page_logic_obeys_permissions_and_protected_conditions_without_writes(): void
    {
        $before = file_get_contents(Form::find('contact')->path());
        $this->callAction('page_path')->assertJsonPath('result.structuredContent.data.path', ['start', 'middle', 'finish']);
        $this->callAction('page_path', ['data' => []])->assertJsonPath('result.structuredContent.data.path', ['start', 'middle', 'finish']);
        $this->callAction('page_path', ['data' => ['choice' => 'skip']])->assertJsonPath('result.structuredContent.data.path', ['start', 'finish']);
        $this->callAction('page_path', ['data' => ['choice' => 'stay']])->assertJsonPath('result.structuredContent.data.path', ['start', 'middle', 'finish']);
        $this->assertSame($before, file_get_contents(Form::find('contact')->path()));
        $this->assertSame(0, Form::find('contact')->querySubmissions()->count());
        $this->callAction('get', ['form' => 'private'])->assertJsonPath('result.structuredContent.error.code', 'FORBIDDEN');
        config(['statamic.mcp.tools.forms.denied_fields' => ['choice']]);
        $this->callAction('page_path', ['data' => ['choice' => 'skip']])->assertJsonPath('result.structuredContent.error.code', 'FORBIDDEN');
    }

    public function test_automagic_settings_are_versioned_replayed_and_never_expose_other_settings(): void
    {
        Form::find('contact')->set('provider_secret', 'never-return')->set('automagic_form_success_heading', 'Done')->save();
        $read = $this->callAction('get')->assertJsonPath('result.structuredContent.success', true);
        $this->assertStringNotContainsString('never-return', $read->getContent());
        $version = $read->json('result.structuredContent.data.version');
        $arguments = ['data' => ['automagic_form' => true],
            'expected_version' => $version, 'idempotency_key' => 'settings-once'];
        $this->callAction('update', $arguments)->assertJsonPath('result.structuredContent.data.settings.automagic_form', true);
        $this->callAction('update', $arguments)->assertJsonPath('result.structuredContent.data.idempotent_replay', true);
        $this->callAction('update', ['expected_version' => $version, 'data' => ['automagic_form' => false]])->assertJsonPath('result.structuredContent.error.code', 'VERSION_CONFLICT');
        $this->assertTrue(Form::find('contact')->get('automagic_form'));
        $this->assertSame('never-return', Form::find('contact')->get('provider_secret'));
        $this->callAction('update', ['data' => ['connections' => []]])->assertJsonPath('result.structuredContent.error.code', 'VALIDATION');
        config(['statamic.mcp.tools.forms-pro.denied_fields' => ['automagic_form_success_heading']]);
        $replay = $this->callAction('update', $arguments)->assertJsonPath('result.structuredContent.success', true);
        $this->assertStringNotContainsString('Done', $replay->getContent());
        $this->assertStringNotContainsString('Done', $this->callAction('get')->getContent());
    }

    public function test_page_rules_round_trip_and_redaction_does_not_reinterpret_field_configuration(): void
    {
        Role::find('form-editor')->permissions(['access cp', 'edit contact form', 'configure form fields'])->save();
        $definition = Form::find('contact')->formFields()->contents();
        $definition['pages'][0]['rules'][0]['conditions'][0]['value'] = 'jump';
        $definition['pages'][1]['sections'] = [['fields' => [
            ['handle' => 'visible', 'field' => ['type' => 'short_answer', 'display' => 'Visible',
                'rules' => ['required'], 'default' => ['fields' => ['literal']]]],
        ]]];
        $this->callAction('update', ['data' => ['fields' => $definition]], 'forms')
            ->assertJsonPath('result.structuredContent.success', true);
        $this->assertSame($definition, Form::find('contact')->formFields()->contents());
        $this->callAction('page_path', ['data' => ['choice' => 'jump']])->assertJsonPath('result.structuredContent.data.path', ['start', 'finish']);
        $this->callAction('page_path', ['data' => ['choice' => 'skip']])->assertJsonPath('result.structuredContent.data.path', ['start', 'middle', 'finish']);
        foreach (['destination' => 'missing', 'conditions' => [['field' => 'choice', 'operator' => 'exec', 'value' => 'x']]] as $key => $value) {
            $invalid = $definition;
            $invalid['pages'][0]['rules'][0][$key] = $value;
            $this->callAction('update', ['data' => ['fields' => $invalid]], 'forms')->assertJsonPath('result.structuredContent.error.code', 'VALIDATION');
            $this->assertSame($definition, Form::find('contact')->formFields()->contents());
        }
        config(['statamic.mcp.tools.forms.denied_fields' => ['choice']]);
        $read = $this->callAction('get', [], 'forms')->assertJsonPath('result.structuredContent.data.form.fields.pages.0.rules', []);
        $this->assertStringNotContainsString('jump', $read->getContent());
        $read->assertJsonPath('result.structuredContent.data.form.fields.pages.1.sections.0.fields.0.field.rules', ['required'])
            ->assertJsonPath('result.structuredContent.data.form.fields.pages.1.sections.0.fields.0.field.default', ['fields' => ['literal']]);
    }

    public function test_address_spec_round_trips_through_the_native_fieldtype_and_refuses_invalid_values(): void
    {
        Collection::make('addresses')->save();
        Blueprint::make('address')->setNamespace('collections.addresses')->setContents(['fields' => [
            ['handle' => 'title', 'field' => ['type' => 'text']],
            ['handle' => 'postal_address', 'field' => ['type' => 'address']],
        ]])->save();
        Stache::refresh();
        $schema = (new BlueprintsRouter)->execute(['action' => 'get', 'handle' => 'address', 'namespace' => 'collections.addresses']);
        $this->assertTrue($schema['success'], json_encode($schema));
        $this->assertStringContainsString('alpha-2', json_encode($schema));
        $address = ['line_1' => 'Example Street 4', 'city' => 'Copenhagen', 'postcode' => '1000', 'country' => 'dk'];
        $args = ['action' => 'create', 'collection' => 'addresses', 'slug' => 'valid', 'data' => ['title' => 'Address', 'postal_address' => $address]];
        $result = (new EntriesRouter)->execute($args);
        $this->assertTrue($result['success'], json_encode($result));
        $id = $result['data']['entry']['id'];
        $address['country'] = 'DK';
        $this->assertSame($address, Entry::find($id)->get('postal_address'));
        foreach (['DK', ['country' => 'DNK'], ['country' => 'XX'], ['postcode' => 1000], ['unexpected' => 'value']] as $invalid) {
            $result = (new EntriesRouter)->execute(['action' => 'update', 'collection' => 'addresses', 'id' => $id, 'data' => ['postal_address' => $invalid]]);
            $this->assertFalse($result['success'], json_encode($result));
            $this->assertSame($address, Entry::find($id)->get('postal_address'));
        }
    }
}

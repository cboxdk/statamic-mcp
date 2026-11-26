<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Tests\Feature\Routers;

use Cboxdk\StatamicMcp\Mcp\Tools\Routers\FormsRouter;
use Cboxdk\StatamicMcp\Tests\TestCase;
use Illuminate\Support\Facades\File;
use Statamic\Facades\Blueprint;
use Statamic\Facades\Form;
use Statamic\Forms\Form as FormObject;

class FormsRouterTest extends TestCase
{
    private FormsRouter $router;

    private string $formsPath;

    protected function setUp(): void
    {
        parent::setUp();

        // Forms are plain files, not Stache items: point them at a per-test
        // directory so nothing leaks into Testbench's skeleton app.
        $this->formsPath = sys_get_temp_dir() . '/statamic-mcp-forms-' . bin2hex(random_bytes(6));
        File::ensureDirectoryExists($this->formsPath);
        config(['statamic.forms.forms' => $this->formsPath]);
        config(['statamic.editions.pro' => true]);

        $this->router = new FormsRouter;
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->formsPath);

        parent::tearDown();
    }

    /**
     * @param  array<string, mixed>  $arguments
     *
     * @return array<string, mixed>
     */
    private function forms(array $arguments): array
    {
        return $this->router->execute($arguments);
    }

    private function find(string $handle): ?FormObject
    {
        $form = Form::find($handle);

        return $form instanceof FormObject ? $form : null;
    }

    private function makeForm(string $handle, string $title = 'Contact'): FormObject
    {
        /** @var FormObject $form */
        $form = Form::make($handle)->title($title);
        $form->save();

        return $form;
    }

    private function errorOf(array $result): string
    {
        return implode(' ', array_map('strval', (array) ($result['errors'] ?? [])));
    }

    public function test_create_saves_every_setting(): void
    {
        $result = $this->forms([
            'action' => 'create',
            'handle' => 'contact',
            'data' => [
                'title' => 'Contact us',
                'honeypot' => 'fax_number',
                'store' => false,
                'email' => [[
                    'to' => 'info@example.com',
                    'subject' => 'New enquiry',
                    'reply_to' => '{{ email }}',
                    'markdown' => true,
                ]],
            ],
        ]);

        $this->assertTrue($result['success'], $this->errorOf($result));
        $this->assertTrue($result['data']['created']);
        $this->assertSame('contact', $result['data']['form']['handle']);

        $form = $this->find('contact');
        $this->assertNotNull($form);
        $this->assertSame('Contact us', $form->title());
        $this->assertSame('fax_number', $form->honeypot());
        $this->assertFalse($form->store());
        $this->assertSame([[
            'to' => 'info@example.com',
            'reply_to' => '{{ email }}',
            'subject' => 'New enquiry',
            'markdown' => true,
        ]], $form->email());
    }

    public function test_create_derives_the_handle_from_the_title(): void
    {
        $result = $this->forms(['action' => 'create', 'data' => ['title' => 'Newsletter Signup']]);

        $this->assertTrue($result['success'], $this->errorOf($result));
        $this->assertSame('newsletter_signup', $result['data']['form']['handle']);
        $this->assertNotNull($this->find('newsletter_signup'));
    }

    public function test_create_requires_a_title(): void
    {
        $result = $this->forms(['action' => 'create', 'handle' => 'contact', 'data' => ['store' => true]]);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('data.title is required', $this->errorOf($result));
        $this->assertNull($this->find('contact'));
    }

    public function test_create_refuses_an_existing_handle(): void
    {
        $this->makeForm('contact', 'Original');

        $result = $this->forms(['action' => 'create', 'handle' => 'contact', 'data' => ['title' => 'Replacement']]);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('already exists', $this->errorOf($result));
        $this->assertSame('Original', $this->find('contact')?->title());
    }

    public function test_a_handle_that_is_not_a_handle_never_reaches_the_filesystem(): void
    {
        foreach (['../escape', 'has-dash', '1starts_with_digit', 'a/b'] as $handle) {
            $create = $this->forms(['action' => 'create', 'handle' => $handle, 'data' => ['title' => 'X']]);
            $this->assertFalse($create['success'], $handle);
            $this->assertStringContainsString('Invalid form handle', $this->errorOf($create));

            $get = $this->forms(['action' => 'get', 'handle' => $handle]);
            $this->assertFalse($get['success'], $handle);
            $this->assertStringContainsString('Invalid form handle', $this->errorOf($get));
        }

        $this->assertFileDoesNotExist(dirname($this->formsPath) . '/escape.yaml');
    }

    public function test_statamic_solo_allows_only_one_form(): void
    {
        config(['statamic.editions.pro' => false]);

        $first = $this->forms(['action' => 'create', 'data' => ['title' => 'Contact']]);
        $this->assertTrue($first['success'], $this->errorOf($first));

        $second = $this->forms(['action' => 'create', 'data' => ['title' => 'Newsletter']]);
        $this->assertFalse($second['success']);
        $this->assertStringContainsString('Statamic Pro is required', $this->errorOf($second));
        $this->assertNull($this->find('newsletter'));
    }

    public function test_unknown_settings_are_refused_not_ignored(): void
    {
        $result = $this->forms(['action' => 'create', 'data' => ['title' => 'Contact', 'stroe' => false]]);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('Unknown form setting(s): stroe', $this->errorOf($result));
        $this->assertNull($this->find('contact'));
    }

    public function test_email_notifications_are_validated(): void
    {
        $this->makeForm('contact');

        $cases = [
            'to is required' => [['subject' => 'Hi']],
            'unknown key(s): recipient' => [['to' => 'a@example.com', 'recipient' => 'b@example.com']],
            'must be a boolean' => [['to' => 'a@example.com', 'markdown' => 'yes']],
            'is not a configured mailer' => [['to' => 'a@example.com', 'mailer' => 'carrier_pigeon']],
            'must be a view name' => [['to' => 'a@example.com', 'html' => '../../secrets']],
            'must be an array of notification objects' => 'a@example.com',
        ];

        foreach ($cases as $expected => $email) {
            $result = $this->forms(['action' => 'update', 'handle' => 'contact', 'data' => ['email' => $email]]);

            $this->assertFalse($result['success'], $expected);
            $this->assertStringContainsString($expected, $this->errorOf($result));
        }

        $this->assertNull($this->find('contact')?->email());
    }

    public function test_a_single_email_object_is_accepted_as_a_list_of_one(): void
    {
        $this->makeForm('contact');

        $result = $this->forms([
            'action' => 'update',
            'handle' => 'contact',
            'data' => ['email' => ['to' => 'info@example.com']],
        ]);

        $this->assertTrue($result['success'], $this->errorOf($result));
        $this->assertSame([['to' => 'info@example.com']], $result['data']['form']['data']['email']);
    }

    public function test_update_changes_only_the_settings_sent(): void
    {
        $form = $this->makeForm('contact', 'Contact');
        $form->honeypot('fax_number')->email([['to' => 'info@example.com']])->save();

        $result = $this->forms(['action' => 'update', 'handle' => 'contact', 'data' => ['store' => false]]);

        $this->assertTrue($result['success'], $this->errorOf($result));
        $this->assertSame(['store'], $result['data']['updated_settings']);

        $form = $this->find('contact');
        $this->assertFalse($form?->store());
        $this->assertSame('Contact', $form?->title());
        $this->assertSame('fax_number', $form?->honeypot());
        $this->assertSame([['to' => 'info@example.com']], $form?->email());
    }

    public function test_update_can_clear_email_and_reset_honeypot(): void
    {
        $form = $this->makeForm('contact');
        $form->honeypot('fax_number')->email([['to' => 'info@example.com']])->save();

        $result = $this->forms([
            'action' => 'update',
            'handle' => 'contact',
            'data' => ['honeypot' => null, 'email' => []],
        ]);

        $this->assertTrue($result['success'], $this->errorOf($result));

        $yaml = (string) file_get_contents($this->formsPath . '/contact.yaml');
        $this->assertStringNotContainsString('honeypot', $yaml);
        $this->assertStringNotContainsString('email', $yaml);
        $this->assertSame('honeypot', $this->find('contact')?->honeypot());
    }

    public function test_honeypot_may_not_name_a_blueprint_field(): void
    {
        $this->makeForm('contact');
        Blueprint::make('contact')->setNamespace('forms')->setContents([
            'fields' => [['handle' => 'email', 'field' => ['type' => 'text']]],
        ])->save();

        $result = $this->forms(['action' => 'update', 'handle' => 'contact', 'data' => ['honeypot' => 'email']]);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('discarded as spam', $this->errorOf($result));
        $this->assertSame('honeypot', $this->find('contact')?->honeypot());
    }

    public function test_honeypot_check_sees_fields_after_the_form_was_saved(): void
    {
        $form = $this->makeForm('contact');
        Blueprint::make('contact')->setNamespace('forms')->setContents([
            'fields' => [['handle' => 'email', 'field' => ['type' => 'text']]],
        ])->save();

        // Statamic 6.44+ moves the fields into the form file here and deletes
        // the blueprint; older versions keep it. The check must hold for both.
        $form->save();

        $result = $this->forms(['action' => 'update', 'handle' => 'contact', 'data' => ['honeypot' => 'email']]);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('discarded as spam', $this->errorOf($result));

        $get = $this->forms(['action' => 'get', 'handle' => 'contact']);
        $this->assertSame(['email'], $get['data']['form']['blueprint']['fields']);
    }

    public function test_get_reports_settings_blueprint_and_mailers(): void
    {
        $form = $this->makeForm('contact');
        $form->email([['to' => 'info@example.com']])->save();
        Blueprint::make('contact')->setNamespace('forms')->setContents([
            'fields' => [['handle' => 'message', 'field' => ['type' => 'textarea']]],
        ])->save();

        $result = $this->forms(['action' => 'get', 'handle' => 'contact']);

        $this->assertTrue($result['success'], $this->errorOf($result));
        $form = $result['data']['form'];
        $this->assertSame('Contact', $form['data']['title']);
        $this->assertSame('honeypot', $form['data']['honeypot']);
        $this->assertTrue($form['data']['store']);
        $this->assertSame([['to' => 'info@example.com']], $form['data']['email']);
        $this->assertSame(['namespace' => 'forms', 'handle' => 'contact', 'exists' => true, 'fields' => ['message']], $form['blueprint']);
        $this->assertContains('smtp', $form['available_mailers']);
    }

    public function test_get_and_update_report_a_missing_form(): void
    {
        foreach (['get' => [], 'update' => ['data' => ['store' => true]], 'delete' => []] as $action => $extra) {
            $result = $this->forms(['action' => $action, 'handle' => 'nope'] + $extra);

            $this->assertFalse($result['success'], $action);
            $this->assertStringContainsString('Form not found: nope', $this->errorOf($result));
        }
    }

    public function test_list_returns_every_form(): void
    {
        $this->makeForm('contact', 'Contact');
        $this->makeForm('newsletter', 'Newsletter');

        $result = $this->forms(['action' => 'list']);

        $this->assertTrue($result['success'], $this->errorOf($result));
        $this->assertEqualsCanonicalizing(['contact', 'newsletter'], array_column($result['data']['forms'], 'handle'));
        $this->assertSame(2, $result['data']['pagination']['total']);
    }

    public function test_delete_removes_the_form(): void
    {
        $this->makeForm('contact');

        $result = $this->forms(['action' => 'delete', 'handle' => 'contact']);

        $this->assertTrue($result['success'], $this->errorOf($result));
        $this->assertSame(0, $result['data']['submissions_deleted']);
        $this->assertNull($this->find('contact'));
    }

    public function test_addon_settings_are_validated_and_stored(): void
    {
        Form::appendConfigFields('*', 'Integrations', [
            'crm_list_id' => ['type' => 'integer', 'validate' => ['required', 'min:1']],
        ]);

        $this->makeForm('contact');

        $invalid = $this->forms(['action' => 'update', 'handle' => 'contact', 'data' => ['crm_list_id' => 0]]);
        $this->assertFalse($invalid['success']);
        $this->assertStringContainsString('crm_list_id', $this->errorOf($invalid));

        $valid = $this->forms(['action' => 'update', 'handle' => 'contact', 'data' => ['crm_list_id' => 42]]);
        $this->assertTrue($valid['success'], $this->errorOf($valid));
        $this->assertSame(42, $this->find('contact')?->get('crm_list_id'));

        $get = $this->forms(['action' => 'get', 'handle' => 'contact']);
        $this->assertSame(42, $get['data']['form']['data']['crm_list_id']);
        $this->assertSame('integer', $get['data']['form']['extra_settings']['crm_list_id']['type']);
    }

    public function test_resource_policy_sees_the_handle_derived_from_the_title(): void
    {
        config(['statamic.mcp.tools.forms.resources.write' => ['contact']]);

        $denied = $this->forms(['action' => 'create', 'data' => ['title' => 'Newsletter']]);
        $this->assertFalse($denied['success']);
        $this->assertStringContainsString("'newsletter' is not permitted", $this->errorOf($denied));
        $this->assertNull($this->find('newsletter'));

        $allowed = $this->forms(['action' => 'create', 'data' => ['title' => 'Contact']]);
        $this->assertTrue($allowed['success'], $this->errorOf($allowed));
    }

    public function test_denied_fields_are_stripped_from_input_and_output(): void
    {
        config(['statamic.mcp.tools.forms.denied_fields' => ['email']]);

        $form = $this->makeForm('contact');
        $form->email([['to' => 'secret@example.com']])->save();

        $get = $this->forms(['action' => 'get', 'handle' => 'contact']);
        $this->assertTrue($get['success'], $this->errorOf($get));
        $this->assertArrayNotHasKey('email', $get['data']['form']['data']);

        $update = $this->forms([
            'action' => 'update',
            'handle' => 'contact',
            'data' => ['title' => 'Renamed', 'email' => [['to' => 'attacker@example.com']]],
        ]);
        $this->assertTrue($update['success'], $this->errorOf($update));
        $this->assertSame([['to' => 'secret@example.com']], $this->find('contact')?->email());
        $this->assertSame('Renamed', $this->find('contact')?->title());
    }
}

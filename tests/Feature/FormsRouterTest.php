<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Tests\Feature;

use Cboxdk\StatamicMcp\Auth\TokenService;
use Cboxdk\StatamicMcp\Contracts\AuditStore;
use Cboxdk\StatamicMcp\Mcp\Tools\Routers\FormsRouter;
use Cboxdk\StatamicMcp\Tests\TestCase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\File;
use Illuminate\Testing\TestResponse;
use Statamic\Events\FormSaving;
use Statamic\Facades\Blueprint;
use Statamic\Facades\Fieldset;
use Statamic\Facades\Form;
use Statamic\Facades\Role;
use Statamic\Facades\Site;
use Statamic\Facades\User;
use Statamic\Forms\Connectors\Email;

class FormsRouterTest extends TestCase
{
    private string $token;

    private string $userId;

    protected function setUp(): void
    {
        parent::setUp();
        config(['statamic.editions.pro' => true, 'statamic.system.multisite' => true]);
        Site::setSites([
            'en' => ['name' => 'English', 'locale' => 'en_US', 'url' => '/'],
            'da' => ['name' => 'Dansk', 'locale' => 'da_DK', 'url' => '/da/'],
        ]);
        Form::make('contact')->title('Contact')->formFields($this->fields())->save();
        Form::make('private')->title('Private')->save();
        $user = User::make()->email('forms@example.test')->makeSuper();
        $user->save();
        $this->userId = $user->id();
        $this->issue(['forms:read', 'forms:write', 'submissions:read', 'submissions:delete']);
        $form = Form::find('contact');
        foreach ([['1700000001.100', 'en', 'finalized'], ['1700000002.100', 'da', 'finalized'],
            ['1700000003.100', 'en', 'partial'], ['1700000004.100', 'en', 'spam']] as [$id, $site, $status]) {
            $submission = $form->makeSubmission()->id($id)->data(['name' => 'Person ' . $id, 'private_note' => 'Never expose'])->site($site);
            if ($status === 'partial') {
                $submission->asPartial();
            } elseif ($status === 'spam') {
                $submission->markAsSpam();
            }
            $submission->save();
        }
    }

    private function fields(): array
    {
        return ['sections' => [['display' => 'Contact', 'fields' => [
            ['handle' => 'name', 'field' => ['type' => 'short_answer', 'display' => 'Name']],
            ['handle' => 'private_note', 'field' => ['type' => 'long_answer', 'display' => 'Private note']],
        ]]]];
    }

    private function issue(array $scopes): void
    {
        $this->token = app(TokenService::class)->createToken($this->userId, 'Forms', $scopes)['token'];
    }

    private function rpc(string $method, array $params): TestResponse
    {
        $headers = ['Authorization' => 'Bearer ' . $this->token, 'MCP-Protocol-Version' => '2026-07-28', 'Mcp-Method' => $method];
        if (isset($params['name'])) {
            $headers['Mcp-Name'] = $params['name'];
        }

        return $this->postJson('/mcp/statamic', ['jsonrpc' => '2.0', 'id' => 1, 'method' => $method,
            'params' => [...$params, '_meta' => ['io.modelcontextprotocol/protocolVersion' => '2026-07-28',
                'io.modelcontextprotocol/clientCapabilities' => []]]], $headers);
    }

    private function formCall(string $action, array $arguments = []): TestResponse
    {
        $facet = (new FormsRouter)->declaredAction($action)->writes() ? 'write' : 'read';

        return $this->rpc('tools/call', ['name' => 'statamic-forms-' . $facet,
            'arguments' => ['action' => $action, ...($action === 'list' ? [] : ['form' => 'contact']), ...$arguments]]);
    }

    public function test_forms_two_definition_round_trip_and_versions(): void
    {
        $created = $this->formCall('create', ['form' => 'survey', 'data' => ['title' => 'Survey', 'fields' => $this->fields(), 'store' => false]])
            ->assertOk()->assertJsonPath('result.structuredContent.success', true);
        $version = $created->json('result.structuredContent.data.form.version');
        $this->assertSame($this->fields(), Form::find('survey')->formFields()->contents());
        $this->assertNull(Blueprint::find('forms.survey'));
        $this->formCall('update', ['form' => 'survey', 'expected_version' => $version, 'data' => ['title' => 'Revised']])
            ->assertJsonPath('result.structuredContent.data.form.title', 'Revised');
        $this->formCall('update', ['form' => 'survey', 'expected_version' => $version, 'data' => ['store' => true]])
            ->assertJsonPath('result.structuredContent.error.code', 'VERSION_CONFLICT');
        $this->assertFalse(Form::find('survey')->store());
    }

    public function test_submissions_are_site_bound_status_filtered_paged_and_redacted(): void
    {
        config(['statamic.mcp.tools.forms.denied_fields' => ['private_note']]);
        $this->formCall('list_submissions')->assertJsonCount(1, 'result.structuredContent.data.items')
            ->assertJsonPath('result.structuredContent.data.items.0.id', '1700000001.100');
        $first = $this->formCall('export', ['filters' => ['status' => 'any'], 'limit' => 2])
            ->assertJsonCount(2, 'result.structuredContent.data.items')->assertJsonPath('result.structuredContent.data.total', 3);
        $this->assertStringNotContainsString('Never expose', $first->getContent());
        $this->assertStringNotContainsString('private_note', $first->getContent());
        $this->formCall('export', ['filters' => ['status' => 'any'], 'limit' => 2, 'cursor' => $first->json('result.structuredContent.data.next_cursor')])
            ->assertJsonPath('result.structuredContent.data.items.0.id', '1700000004.100')->assertJsonPath('result.structuredContent.data.next_cursor', null);
        $this->formCall('summary', ['filters' => ['status' => 'any']])->assertJsonPath('result.structuredContent.data.summary',
            ['form' => 'contact', 'site' => 'en', 'total' => 3, 'finalized' => 1, 'partial' => 1, 'spam' => 1]);
        $this->formCall('get_submission', ['id' => '1700000002.100'])->assertJsonPath('result.structuredContent.error.code', 'NOT_FOUND');
        $this->formCall('get_submission', ['form' => 'private', 'site' => 'en', 'id' => '1700000001.100'])->assertJsonPath('result.structuredContent.error.code', 'NOT_FOUND');
        $this->formCall('get')->assertJsonCount(1, 'result.structuredContent.data.form.fields.sections.0.fields');
    }

    public function test_dates_and_legacy_default_site_rows_are_filtered(): void
    {
        Form::find('contact')->makeSubmission()->id('1700000005.100')->data(['name' => 'Legacy'])->save();
        $this->formCall('list_submissions', ['filters' => ['date_from' => '2023-11-14T22:13:25Z', 'date_to' => '2023-11-14T22:13:26Z']])
            ->assertJsonCount(1, 'result.structuredContent.data.items')->assertJsonPath('result.structuredContent.data.items.0.data.name', 'Legacy');
        $this->formCall('list_submissions', ['filters' => ['private_note' => 'Never expose']])
            ->assertJsonPath('result.structuredContent.error.code', 'VALIDATION');
    }

    public function test_form_scope_never_grants_submission_data_and_submission_only_tokens_see_the_tool(): void
    {
        $this->issue(['forms:read']);
        $this->formCall('get')->assertJsonPath('result.structuredContent.success', true);
        $this->formCall('get_submission', ['id' => '1700000001.100'])->assertForbidden()->assertJsonPath('error', 'insufficient_scope');
        $this->issue(['submissions:read']);
        $tools = $this->rpc('tools/list', [])->assertOk()->json('result.tools');
        $this->assertContains('statamic-forms-read', array_column($tools, 'name'));
        $this->formCall('get_submission', ['id' => '1700000001.100'])->assertJsonPath('result.structuredContent.success', true);
        $this->formCall('get')->assertForbidden()->assertJsonPath('error', 'insufficient_scope');
    }

    public function test_native_permissions_and_site_policy_apply_to_non_super_users(): void
    {
        Role::make()->handle('form-reader')->permissions(['access cp', 'access en site', 'view contact form submissions'])->save();
        $user = User::find($this->userId)->set('super', false)->assignRole('form-reader');
        $user->save();
        $this->formCall('list')->assertJsonCount(1, 'result.structuredContent.data.items');
        $this->formCall('get_submission', ['id' => '1700000001.100'])->assertJsonPath('result.structuredContent.success', true);
        $this->formCall('list_submissions', ['site' => 'da'])->assertJsonPath('result.structuredContent.error.code', 'FORBIDDEN');
        $this->formCall('list_submissions', ['form' => 'private'])->assertJsonPath('result.structuredContent.error.code', 'FORBIDDEN');
        $this->formCall('update', ['data' => ['title' => 'No']])->assertJsonPath('result.structuredContent.error.code', 'FORBIDDEN');
    }

    public function test_spam_is_a_write_and_partial_submissions_remain_partial(): void
    {
        $this->issue(['submissions:read']);
        $this->formCall('mark_spam', ['id' => '1700000001.100'])->assertForbidden()->assertJsonPath('error', 'insufficient_scope');
        $this->issue(['forms:write', 'submissions:read']);
        $this->formCall('mark_spam', ['id' => '1700000001.100', 'idempotency_key' => 'spam-once'])->assertJsonPath('result.structuredContent.data.submission.status', 'spam');
        $this->formCall('mark_spam', ['id' => '1700000001.100', 'idempotency_key' => 'spam-once'])->assertJsonPath('result.structuredContent.data.idempotent_replay', true);
        $this->formCall('mark_spam', ['id' => '1700000003.100'])->assertJsonPath('result.structuredContent.error.code', 'VALIDATION');
        $this->assertTrue(Form::find('contact')->submission('1700000003.100')->isPartial());
    }

    public function test_creating_a_form_does_not_grant_field_or_connector_permissions(): void
    {
        Role::make()->handle('creator')->permissions(['access cp', 'create forms'])->save();
        User::find($this->userId)->set('super', false)->assignRole('creator')->save();
        foreach (['fields' => $this->fields(), 'connections' => ['email' => []], 'store' => false] as $key => $value) {
            $this->formCall('create', ['form' => 'survey', 'data' => ['title' => 'Survey', $key => $value]])
                ->assertJsonPath('result.structuredContent.error.code', 'FORBIDDEN');
            $this->assertNull(Form::find('survey'));
        }
        $this->formCall('create', ['form' => 'survey', 'data' => ['title' => 'Survey']])->assertJsonPath('result.structuredContent.success', true);
    }

    public function test_submission_status_changes_invalidate_versions_and_pending_approvals(): void
    {
        $version = $this->formCall('get_submission', ['id' => '1700000001.100'])->json('result.structuredContent.data.submission.version');
        config(['statamic.mcp.confirmation.enabled' => true, 'statamic.users.elevated_sessions_enabled' => true]);
        $approval = $this->formCall('delete_submission', ['id' => '1700000001.100'])->json('result.structuredContent.data.approval_id');
        $this->actingAs(User::find($this->userId))->withSession(['statamic_elevated_session' => now()->timestamp])
            ->postJson(cp_route('statamic-mcp.approvals.approve', ['approval' => $approval]))->assertOk();
        Form::find('contact')->submission('1700000001.100')->markAsSpam()->save();
        $this->formCall('delete_submission', ['id' => '1700000001.100', 'approval_id' => $approval])
            ->assertJsonPath('result.structuredContent.error.code', 'APPROVAL_STALE');
        config(['statamic.mcp.confirmation.enabled' => false]);
        $this->formCall('delete_submission', ['id' => '1700000001.100', 'expected_version' => $version])
            ->assertJsonPath('result.structuredContent.error.code', 'VERSION_CONFLICT');
        $this->assertNotNull(Form::find('contact')->submission('1700000001.100'));
    }

    public function test_legacy_email_connections_have_stable_versions(): void
    {
        File::put(Form::find('contact')->path(), "title: Contact\nemail:\n  - to: 'test@example.test'\n");
        $version = $this->formCall('get')->json('result.structuredContent.data.form.version');
        $this->formCall('get')->assertJsonPath('result.structuredContent.data.form.version', $version);
        $this->formCall('update', ['expected_version' => $version, 'data' => ['title' => 'New title']])
            ->assertJsonPath('result.structuredContent.success', true);
    }

    public function test_replays_and_nested_definitions_obey_current_denied_fields(): void
    {
        $fields = ['sections' => [['fields' => [['handle' => 'group', 'field' => ['type' => 'group', 'fields' => [
            ['handle' => 'private_note', 'field' => ['type' => 'short_answer', 'default' => 'nested-secret']],
            ['handle' => 'visible', 'field' => ['type' => 'short_answer']],
        ]]]]]]];
        Form::find('contact')->formFields($fields)->save();
        $formWrite = ['data' => ['title' => 'Renamed'], 'idempotency_key' => 'form-replay'];
        $spamWrite = ['id' => '1700000001.100', 'idempotency_key' => 'spam-replay'];
        $this->formCall('update', $formWrite)->assertJsonPath('result.structuredContent.success', true);
        $this->formCall('mark_spam', $spamWrite)->assertJsonPath('result.structuredContent.success', true);
        config(['statamic.mcp.tools.forms.denied_fields' => ['private_note']]);
        foreach ([$this->formCall('get'), $this->formCall('update', $formWrite), $this->formCall('mark_spam', $spamWrite)] as $response) {
            $response->assertJsonPath('result.structuredContent.success', true);
            $this->assertStringNotContainsString('private_note', $response->getContent());
            $this->assertStringNotContainsString('nested-secret', $response->getContent());
            $this->assertStringNotContainsString('Never expose', $response->getContent());
        }
        $this->formCall('get')->assertJsonPath('result.structuredContent.data.form.fields.sections.0.fields.0.field.fields.0.handle', 'visible');
        $this->formCall('update', ['data' => ['fields' => ['sections' => []]]])->assertJsonPath('result.structuredContent.error.code', 'FORBIDDEN');
        $this->assertSame($fields, Form::find('contact')->formFields()->contents());
    }

    public function test_delete_requires_its_scope_and_approval_and_checks_binding(): void
    {
        config(['statamic.mcp.confirmation.enabled' => true]);
        $this->issue(['forms:write']);
        $this->formCall('delete_submission', ['id' => '1700000001.100'])->assertForbidden()->assertJsonPath('error', 'insufficient_scope');
        $this->issue(['submissions:delete']);
        $this->formCall('delete_submission', ['id' => '1700000001.100'])->assertJsonPath('result.structuredContent.error.code', 'APPROVAL_REQUIRED');
        $this->assertNotNull(Form::find('contact')->submission('1700000001.100'));
        config(['statamic.mcp.confirmation.enabled' => false]);
        $this->formCall('delete_submission', ['id' => '1700000002.100'])->assertJsonPath('result.structuredContent.error.code', 'NOT_FOUND');
        $deleted = $this->formCall('delete_submission', ['id' => '1700000001.100'])->assertJsonPath('result.structuredContent.data.submission.deleted', true);
        $this->assertStringNotContainsString('Never expose', $deleted->getContent());
        $this->assertNull(Form::find('contact')->submission('1700000001.100'));
    }

    public function test_deleted_submissions_replay_without_bypassing_authorization(): void
    {
        $arguments = ['id' => '1700000001.100', 'idempotency_key' => 'delete-once'];
        $this->formCall('delete_submission', $arguments)->assertJsonPath('result.structuredContent.data.submission.deleted', true);
        $this->formCall('delete_submission', $arguments)->assertJsonPath('result.structuredContent.data.idempotent_replay', true);
        $this->formCall('delete_submission', [...$arguments, 'site' => 'da'])->assertJsonPath('result.structuredContent.success', false);
        $this->issue(['forms:read']);
        $this->formCall('delete_submission', $arguments)->assertForbidden();
    }

    public function test_approved_delete_replays_its_receipt_after_the_record_is_gone(): void
    {
        config(['statamic.mcp.confirmation.enabled' => true, 'statamic.users.elevated_sessions_enabled' => true]);
        $asked = $this->formCall('delete_submission', ['id' => '1700000001.100']);
        $approval = $asked->json('result.structuredContent.data.approval_id');
        $this->actingAs(User::find($this->userId))->withSession(['statamic_elevated_session' => now()->timestamp])
            ->postJson(cp_route('statamic-mcp.approvals.approve', ['approval' => $approval]))->assertOk();
        $arguments = ['id' => '1700000001.100', 'approval_id' => $approval];
        $this->formCall('delete_submission', $arguments)->assertJsonPath('result.structuredContent.data.submission.deleted', true);
        $this->formCall('delete_submission', $arguments)->assertJsonPath('result.structuredContent.success', true);
        $this->assertNull(Form::find('contact')->submission('1700000001.100'));
    }

    public function test_invalid_definitions_and_vetoed_saves_do_not_report_success(): void
    {
        $fields = $this->fields();
        $fields['sections'][0]['fields'][] = $fields['sections'][0]['fields'][0];
        $this->formCall('update', ['data' => ['fields' => $fields]])->assertJsonPath('result.structuredContent.error.code', 'VALIDATION');
        $this->formCall('update', ['data' => ['honeypot' => 'name']])->assertJsonPath('result.structuredContent.error.code', 'VALIDATION');
        $this->formCall('update', ['data' => ['unknown' => true]])->assertJsonPath('result.structuredContent.error.code', 'VALIDATION');
        foreach ([42, ['type' => 'not_installed'], ['type' => 42]] as $invalid) {
            $fields = ['sections' => [['fields' => [['handle' => 'answer', 'field' => $invalid]]]]];
            $this->formCall('update', ['data' => ['fields' => $fields]])->assertJsonPath('result.structuredContent.error.code', 'VALIDATION');
        }
        foreach (['upload', 'not_installed'] as $type) {
            $fields = ['sections' => [['fields' => [['handle' => 'group', 'field' => ['type' => 'group', 'fields' => [
                ['handle' => 'answer', 'field' => ['type' => $type]],
            ]]]]]]];
            $this->formCall('update', ['data' => ['fields' => $fields]])->assertJsonPath('result.structuredContent.error.code', 'VALIDATION');
            $this->assertSame($this->fields(), Form::find('contact')->formFields()->contents());
        }
        Event::listen(FormSaving::class, fn () => false);
        $this->formCall('update', ['data' => ['title' => 'Vetoed']])->assertJsonPath('result.structuredContent.success', false);
        $this->assertSame('Contact', Form::find('contact')->title());
    }

    public function test_native_group_handles_and_flattened_imports_round_trip(): void
    {
        Fieldset::make('contact_details')->setContents(['fields' => [
            ['handle' => 'name', 'field' => ['type' => 'text']],
        ]])->save();
        $fields = ['sections' => [['fields' => [
            ['import' => 'contact_details', 'section_behavior' => 'flatten'],
            ['handle' => 'group', 'field' => ['type' => 'group', 'fields' => [
                ['handle' => 'message', 'field' => ['type' => 'long_answer']],
            ]]],
        ]]]];
        $this->formCall('update', ['data' => ['fields' => $fields]])->assertJsonPath('result.structuredContent.success', true);
        $this->formCall('get')->assertJsonPath('result.structuredContent.data.form.fields', $fields);
        $this->assertSame($fields, Form::find('contact')->formFields()->contents());
        $fields['sections'][0]['fields'] = [['handle' => 'message', 'field' => ['type' => 'long_answer']]];
        $this->formCall('update', ['data' => ['fields' => $fields]])->assertJsonPath('result.structuredContent.error.code', 'VALIDATION');
    }

    public function test_resource_policy_applies_in_stdio_and_http(): void
    {
        config(['statamic.mcp.tools.forms.resources.read' => ['private']]);
        $this->formCall('list')->assertJsonCount(1, 'result.structuredContent.data.items')->assertJsonPath('result.structuredContent.data.items.0.handle', 'private');
        $this->formCall('get_submission', ['id' => '1700000001.100'])->assertJsonPath('result.structuredContent.error.code', 'FORBIDDEN');
        $result = (new FormsRouter)->execute(['action' => 'get', 'form' => 'contact']);
        $this->assertFalse($result['success']);
    }

    public function test_connector_credentials_never_leave_writes_in_responses_previews_or_audit(): void
    {
        ProbeConnector::register();
        $data = ['connections' => ['probe' => [['provider_value' => 'synthetic-credential']]]];
        $saved = $this->formCall('update', ['data' => $data])->assertJsonPath('result.structuredContent.success', true);
        $this->assertSame('synthetic-credential', Form::find('contact')->connections()->get('probe')[0]['provider_value']);
        $read = $this->formCall('get')->assertJsonPath('result.structuredContent.data.form.connections.0.count', 1);
        $this->assertStringNotContainsString('synthetic-credential', $saved->getContent() . $read->getContent());

        config(['statamic.mcp.confirmation.enabled' => true, 'statamic.mcp.confirmation.actions.forms' => ['update']]);
        $asked = $this->formCall('update', ['data' => $data])->assertJsonPath('result.structuredContent.error.code', 'APPROVAL_REQUIRED');
        $this->assertStringNotContainsString('synthetic-credential', $asked->getContent());
        $preview = $this->actingAs(User::find($this->userId))->get(cp_route('statamic-mcp.approvals.index'))->assertOk();
        $this->assertStringNotContainsString('synthetic-credential', $preview->getContent());

        config(['statamic.mcp.confirmation.enabled' => false]);
        $failed = $this->formCall('update', ['data' => ['connections' => ['probe' => [['provider_value' => 'synthetic-rejected-credential']]]]])
            ->assertJsonPath('result.structuredContent.error.code', 'VALIDATION');
        $audit = app(AuditStore::class)->query(null, null, 1, 100)->entries;
        $this->assertStringNotContainsString('synthetic-credential', json_encode($audit));
        $this->assertStringNotContainsString('synthetic-rejected-credential', $failed->getContent() . json_encode($audit));
    }
}

class ProbeConnector extends Email
{
    protected static $handle = 'probe';

    public function blueprint(): \Statamic\Fields\Blueprint
    {
        return Blueprint::make()->setContents(['fields' => [['handle' => 'provider_value', 'field' => ['type' => 'text', 'validate' => 'required']]]]);
    }

    protected function processConnection(array $connection): array
    {
        if (($connection['provider_value'] ?? null) === 'synthetic-rejected-credential') {
            throw new \RuntimeException($connection['provider_value']);
        }

        return parent::processConnection($connection);
    }
}

<?php

declare(strict_types=1);

use Cboxdk\StatamicMcp\Mcp\Prompts\ContentWorkflowPrompt;
use Cboxdk\StatamicMcp\Mcp\Tools\Routers\EntriesRouter;
use Cboxdk\StatamicMcp\Testing\InteractsWithMcp;
use Cboxdk\StatamicMcp\Tests\TestCase;
use Statamic\Facades\Blueprint;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;
use Statamic\Facades\Site;

class PromptsTest extends TestCase
{
    use InteractsWithMcp;

    public function test_prompts_embed_live_formats_and_content_without_writing(): void
    {
        Collection::make('prompt_pages')->save();
        Blueprint::make('page')->setNamespace('collections.prompt_pages')->setContents(['fields' => [
            ['handle' => 'title', 'field' => ['type' => 'text']],
            ['handle' => 'sections', 'field' => ['type' => 'replicator', 'sets' => [
                'text' => ['fields' => [['handle' => 'body', 'field' => ['type' => 'markdown']]]],
            ]]],
        ]])->save();
        $entry = Entry::make()->id('prompt-entry')->collection('prompt_pages')->slug('page')->data([
            'title' => 'Untouched title', 'sections' => [['id' => 'section-42', 'type' => 'text', 'body' => 'Original paragraph']],
        ]);
        $entry->save();
        $before = file_get_contents($entry->path());

        foreach (ContentWorkflowPrompt::NAMES as $workflow) {
            $arguments = ['collection' => 'prompt_pages', 'blueprint' => 'page', 'task' => 'Improve the wording'];
            if ($workflow !== 'model_content') {
                $arguments['id'] = 'prompt-entry';
            }
            if ($workflow === 'translate_entry') {
                $arguments['target_site'] = 'danish';
            }
            if ($workflow === 'edit_page_builder') {
                $arguments['field'] = 'sections';
            }
            $response = $this->mcpPrompt(new ContentWorkflowPrompt($workflow), $arguments)
                ->assertOk()->assertSee(['Improve the wording', '_format_spec', 'APPROVAL_REQUIRED', 'OUTCOME_UNKNOWN'])
                ->assertDontSee(['dry_run', 'confirm=true', 'statamic-discovery', 'statamic-schema']);
            if ($workflow !== 'model_content') {
                $response->assertSee(['section-42', 'Original paragraph']);
            }
            if ($workflow === 'edit_page_builder') {
                $response->assertDontSee('Untouched title');
            }
        }
        $this->assertSame($before, file_get_contents($entry->path()));
        $this->assertSame(1, Entry::query()->where('collection', 'prompt_pages')->count());
    }

    public function test_prompt_arguments_are_validated_before_returning_instructions(): void
    {
        $this->mcpPrompt(new ContentWorkflowPrompt('translate_entry'), [
            'collection' => 'prompt_pages', 'blueprint' => 'page', 'task' => 'Translate', 'id' => 'entry',
        ])->assertHasErrors()->assertSee('target site');
        $this->mcpPrompt(new ContentWorkflowPrompt('edit_page_builder'), [
            'collection' => 'prompt_pages', 'blueprint' => 'page', 'task' => 'Edit', 'id' => 'entry', 'field' => 'sections.0',
        ])->assertHasErrors()->assertSee('field');
    }

    public function test_existing_entry_rejects_an_unrelated_blueprint(): void
    {
        Collection::make('prompt_pages')->save();
        foreach (['article', 'landing'] as $handle) {
            Blueprint::make($handle)->setNamespace('collections.prompt_pages')->setContents([
                'fields' => [['handle' => 'title', 'field' => ['type' => 'text']]],
            ])->save();
        }
        Entry::make()->id('article')->collection('prompt_pages')->blueprint('article')->slug('article')->save();
        $this->mcpPrompt(new ContentWorkflowPrompt, [
            'collection' => 'prompt_pages', 'blueprint' => 'landing', 'id' => 'article', 'task' => 'Edit hero',
        ])->assertHasErrors()->assertSee('does not match');
    }

    public function test_page_builder_supports_unset_and_inherited_fields_but_not_denied_fields(): void
    {
        config(['statamic.system.multisite' => true]);
        Site::setSites([
            'en' => ['name' => 'English', 'locale' => 'en_US', 'url' => '/'],
            'da' => ['name' => 'Danish', 'locale' => 'da_DK', 'url' => '/da'],
        ]);
        Collection::make('prompt_pages')->sites(['en', 'da'])->save();
        Blueprint::make('page')->setNamespace('collections.prompt_pages')->setContents(['fields' => [
            ['handle' => 'sections', 'field' => ['type' => 'replicator', 'localizable' => true]],
        ]])->save();
        $entry = Entry::make()->id('source')->collection('prompt_pages')->locale('en')->slug('source');
        $entry->save();
        $arguments = ['collection' => 'prompt_pages', 'blueprint' => 'page', 'id' => 'source', 'field' => 'sections', 'task' => 'Add a section'];
        $this->mcpPrompt(new ContentWorkflowPrompt('edit_page_builder'), $arguments)
            ->assertOk()->assertSee('"present":false');
        $entry->data(['sections' => [['id' => 'inherited-id', 'type' => 'text', 'body' => 'Inherited paragraph']]])->save();
        $entry->makeLocalization('da')->save();
        $this->mcpPrompt(new ContentWorkflowPrompt('edit_page_builder'), [...$arguments, 'site' => 'da'])
            ->assertOk()->assertSee(['Inherited paragraph', '"source_site":"en"', 'complete inherited array', 'merge_sets=false']);
        config(['statamic.mcp.tools.entries.denied_fields' => ['sections']]);
        $this->mcpPrompt(new ContentWorkflowPrompt('edit_page_builder'), [...$arguments, 'site' => 'da'])
            ->assertHasErrors()->assertDontSee('Inherited paragraph');
        foreach (['origin', 'blueprint'] as $metadata) {
            config(['statamic.mcp.tools.entries.denied_fields' => [$metadata]]);
            $router = new EntriesRouter;
            $result = $router->execute(['action' => 'get', 'collection' => 'prompt_pages', 'id' => 'source', 'site' => 'da']);
            $this->assertTrue($result['success']);
            $this->assertArrayNotHasKey($metadata, $result['data']['entry']);
            $this->mcpPrompt(new ContentWorkflowPrompt('edit_page_builder'), [...$arguments, 'site' => 'da'])
                ->assertHasErrors()->assertDontSee('Inherited paragraph');
        }
    }

    public function test_blueprint_defaults_are_filtered_in_full_and_selected_prompt_context(): void
    {
        Collection::make('prompt_pages')->save();
        Blueprint::make('page')->setNamespace('collections.prompt_pages')->setContents(['fields' => [
            ['handle' => 'sections', 'field' => ['type' => 'replicator', 'default' => 'private-default']],
        ]])->save();
        Entry::make()->id('source')->collection('prompt_pages')->slug('source')->save();
        config(['statamic.mcp.tools.blueprints.denied_fields' => ['default']]);
        foreach (['model_content', 'edit_page_builder'] as $name) {
            $arguments = ['collection' => 'prompt_pages', 'blueprint' => 'page', 'task' => 'Review fields'];
            if ($name === 'edit_page_builder') {
                $arguments += ['id' => 'source', 'field' => 'sections'];
            }
            $this->mcpPrompt(new ContentWorkflowPrompt($name), $arguments)->assertOk()->assertDontSee('private-default');
        }
    }

    public function test_prompt_limit_covers_the_combined_context(): void
    {
        Collection::make('prompt_pages')->save();
        Blueprint::make('page')->setNamespace('collections.prompt_pages')->setContents(['fields' => [
            ['handle' => 'title', 'field' => ['type' => 'text', 'instructions' => str_repeat('b', 4000)]],
        ]])->save();
        Entry::make()->id('large')->collection('prompt_pages')->slug('large')->data(['title' => str_repeat('e', 4000)])->save();
        config(['statamic.mcp.security.max_response_size' => 10000]);
        $this->mcpPrompt(new ContentWorkflowPrompt, [
            'collection' => 'prompt_pages', 'blueprint' => 'page', 'id' => 'large', 'task' => 'Improve',
        ])->assertHasErrors()->assertSee('Prompt context exceeds');
    }

    public function test_completions_find_handles_after_the_first_page(): void
    {
        for ($i = 0; $i < 501; $i++) {
            Collection::make(sprintf('collection_%03d', $i))->save();
        }
        $completion = (new ContentWorkflowPrompt)->complete('collection', 'collection_500', [])->toArray();
        $this->assertSame(['collection_500'], $completion['values']);
        $this->assertFalse($completion['hasMore']);
    }
}

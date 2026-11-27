<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Tests\Feature;

use Cboxdk\StatamicMcp\Mcp\Tools\Routers\BlueprintsRouter;
use Cboxdk\StatamicMcp\Mcp\Tools\Routers\EntriesRouter;
use Cboxdk\StatamicMcp\Tests\TestCase;
use Illuminate\Support\Facades\File;
use Opis\JsonSchema\Validator;
use Statamic\Facades\Blueprint;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;
use Statamic\Facades\Stache;

class ResponseSizeLimitTest extends TestCase
{
    private EntriesRouter $router;

    private string $collectionHandle;

    private string $operationsPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->router = new EntriesRouter;
        $this->collectionHandle = 'pages-' . bin2hex(random_bytes(4));
        $this->operationsPath = storage_path('framework/testing/' . $this->collectionHandle);
        config(['statamic.mcp.storage.operations_path' => $this->operationsPath]);
        Collection::make($this->collectionHandle)->title('Pages')->save();

        Blueprint::make('pages')->setNamespace("collections.{$this->collectionHandle}")->setContents([
            'fields' => [
                ['handle' => 'title', 'field' => ['type' => 'text']],
                ['handle' => 'body', 'field' => ['type' => 'textarea']],
            ],
        ])->save();

        Entry::make()
            ->id('big')
            ->collection($this->collectionHandle)
            ->slug('big')
            ->data(['title' => 'Big', 'body' => str_repeat('a', 20000)])
            ->save();

        Stache::refresh();
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->operationsPath);
        parent::tearDown();
    }

    /**
     * @return array<string, mixed>
     */
    private function fetch(): array
    {
        return $this->router->execute([
            'action' => 'get',
            'collection' => $this->collectionHandle,
            'id' => 'big',
        ]);
    }

    public function test_the_default_limit_is_unchanged(): void
    {
        $this->assertSame(100000, config('statamic.mcp.security.max_response_size'));
        $this->assertTrue($this->fetch()['success']);
    }

    public function test_a_lower_limit_refuses_the_response(): void
    {
        config(['statamic.mcp.security.max_response_size' => 1000]);

        $result = $this->fetch();

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('Response too large', implode(' ', $result['errors']));
    }

    public function test_a_raised_limit_allows_a_response_the_default_would_refuse(): void
    {
        config(['statamic.mcp.security.max_response_size' => 1000]);
        $this->assertFalse($this->fetch()['success']);

        config(['statamic.mcp.security.max_response_size' => 500000]);
        $this->assertTrue($this->fetch()['success']);
    }

    public function test_zero_disables_the_guard(): void
    {
        config(['statamic.mcp.security.max_response_size' => 0]);

        $this->assertTrue($this->fetch()['success']);
    }

    public function test_a_large_page_builder_can_be_read_in_parts_without_reindexing_its_arrays(): void
    {
        $sections = [
            ['id' => 'first', 'text' => str_repeat('a', 75000)],
            ['id' => 'second', 'text' => str_repeat('b', 75000)],
        ];
        Entry::find('big')->set('sections', $sections)->save();
        $this->assertFalse($this->fetch()['success']);

        foreach ($sections as $index => $section) {
            $path = "entry.data.sections.{$index}";
            $result = $this->router->facet('read')->execute([
                'action' => 'get', 'collection' => $this->collectionHandle, 'id' => 'big',
                'fields' => ['entry.version', $path],
            ]);
            $this->assertTrue($result['success'], json_encode($result));
            $this->assertTrue($result['data']['projection']);
            $this->assertSame($section, $result['data']['fields'][1]['value']);
            $this->assertSame($path, $result['data']['fields'][1]['path']);
            $this->assertLessThanOrEqual(100000, strlen(json_encode($result)));
            $this->assertTrue((new Validator)->validate(
                json_decode(json_encode($result)),
                json_decode(json_encode($this->router->facet('read')->toArray()['outputSchema'])),
            )->isValid());
        }
    }

    public function test_projection_cannot_recover_denied_fields_or_turn_missing_paths_into_null(): void
    {
        config(['statamic.mcp.tools.entries.denied_fields' => ['body']]);
        foreach (['entry.data.body', 'entry.data.missing', 'entry.data.*'] as $path) {
            $result = $this->router->execute([
                'action' => 'get', 'collection' => $this->collectionHandle, 'id' => 'big', 'fields' => [$path],
            ]);
            $this->assertFalse($result['success']);
            $this->assertSame('VALIDATION', $result['error']['code']);
            $this->assertStringNotContainsString(str_repeat('a', 100), json_encode($result));
        }
    }

    public function test_size_limited_pages_visit_every_record_once(): void
    {
        config(['statamic.mcp.security.max_response_size' => 2000, 'statamic.mcp.tools.entries.denied_fields' => ['body']]);
        for ($i = 0; $i < 20; $i++) {
            Entry::make()->id(sprintf('page-%04d', $i))->collection($this->collectionHandle)
                ->slug("page-{$i}")->data(['title' => "Page {$i}"])->save();
        }
        $seen = [];
        $cursor = null;
        do {
            $result = $this->router->execute([
                'action' => 'list', 'collection' => $this->collectionHandle, 'limit' => 20, 'cursor' => $cursor,
                'filters' => ['body' => 'a denied filter is removed before querying'],
            ]);
            $this->assertTrue($result['success'], json_encode($result));
            $this->assertLessThanOrEqual(2000, strlen(json_encode($result)));
            $this->assertNotEmpty($result['data']['items']);
            $this->assertSame(21, $result['data']['total']);
            $seen = [...$seen, ...array_column($result['data']['items'], 'id')];
            $cursor = $result['data']['next_cursor'];
            if ($cursor !== null) {
                $this->assertTrue($result['data']['truncated']);
            }
            $this->assertLessThanOrEqual(21, count($seen));
        } while ($cursor !== null);
        $this->assertCount(21, array_unique($seen));
    }

    public function test_late_duration_metadata_cannot_overflow_a_full_page(): void
    {
        for ($i = 0; $i < 3; $i++) {
            Entry::make()->id("late-{$i}")->collection($this->collectionHandle)->slug("late-{$i}")
                ->data(['title' => str_repeat('x', 1000)])->save();
        }
        $arguments = ['action' => 'list', 'collection' => $this->collectionHandle];
        $limit = strlen(json_encode($this->router->execute($arguments)));
        config(['statamic.mcp.security.max_response_size' => $limit, 'statamic.mcp.security.tool_timeout_seconds' => 0]);
        $response = $this->router->execute($arguments);
        $this->assertTrue($response['success'], json_encode($response));
        $this->assertTrue($response['meta']['duration_budget_exceeded']);
        $this->assertLessThanOrEqual($limit, strlen(json_encode($response)));
        $this->assertTrue($response['data']['truncated']);
        $this->assertNotNull($response['data']['next_cursor']);
    }

    public function test_a_single_oversized_list_item_is_reported_instead_of_skipped(): void
    {
        Entry::find('big')->set('title', str_repeat('x', 150000))->save();
        $result = $this->router->execute(['action' => 'list', 'collection' => $this->collectionHandle]);
        $this->assertFalse($result['success']);
        $this->assertSame('TOO_LARGE', $result['error']['code']);
        $this->assertStringContainsString('get', $result['error']['hint']);
    }

    public function test_a_saved_write_with_an_oversized_response_still_succeeds(): void
    {
        // The write landed; reporting it as an error made agents retry and
        // create the record twice.
        config(['statamic.mcp.security.max_response_size' => 5000]);

        $arguments = [
            'action' => 'create',
            'collection' => $this->collectionHandle,
            'data' => ['title' => 'Huge', 'slug' => 'huge', 'body' => str_repeat('b', 20000)],
            'idempotency_key' => 'large-write-retry',
        ];
        $result = $this->router->execute($arguments);

        $this->assertTrue($result['success'], json_encode($result));
        $this->assertSame('committed', $result['meta']['write_effects']);
        $this->assertTrue($result['data']['response_truncated']);
        $this->assertSame('huge', $result['data']['entry']['slug']);
        $this->assertArrayHasKey('version', $result['data']['entry']);
        $this->assertArrayNotHasKey('data', $result['data']['entry']);
        $this->assertLessThan(5000, strlen((string) json_encode($result)));
        $replayed = $this->router->execute($arguments);
        $this->assertTrue($replayed['success'], json_encode($replayed));
        $this->assertTrue($replayed['data']['idempotent_replay']);
        $this->assertTrue($replayed['data']['response_truncated']);
        $this->assertLessThan(5000, strlen((string) json_encode($replayed)));
        $this->assertCount(1, Entry::query()->where('collection', $this->collectionHandle)->where('slug', 'huge')->get());
    }

    public function test_an_oversized_title_cannot_make_the_saved_write_receipt_oversized(): void
    {
        config(['statamic.mcp.security.max_response_size' => 2000]);
        $result = (new BlueprintsRouter)->execute([
            'action' => 'create', 'namespace' => 'globals', 'handle' => 'huge_title',
            'title' => str_repeat('x', 150000),
            'fields' => [['handle' => 'title', 'field' => ['type' => 'text']]],
        ]);
        $this->assertTrue($result['success'], json_encode($result));
        $this->assertTrue($result['data']['response_truncated']);
        $this->assertSame('huge_title', $result['data']['blueprint']['handle']);
        $this->assertLessThanOrEqual(2000, strlen(json_encode($result)));
        $this->assertSame(str_repeat('x', 150000), Blueprint::find('globals.huge_title')->title());
    }
}

<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Tests\Feature\Routers;

use Cboxdk\StatamicMcp\Mcp\Exceptions\FieldFormatException;
use Cboxdk\StatamicMcp\Mcp\Support\FieldtypeExtensions;
use Cboxdk\StatamicMcp\Mcp\Tools\Routers\BlueprintsRouter;
use Cboxdk\StatamicMcp\Mcp\Tools\Routers\EntriesRouter;
use Cboxdk\StatamicMcp\Tests\TestCase;
use Statamic\Facades\Blueprint;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;
use Statamic\Facades\Stache;
use Statamic\Fields\Field;

/**
 * Proves the registry is actually reached by the two paths that matter: the
 * blueprint's wire-format spec, and the entry write pipeline.
 *
 * `list` stands in for any fieldtype the package has no arm for — the same
 * position `seo` from aerni/advanced-seo occupies on a real site.
 */
class FieldtypeExtensionsWiringTest extends TestCase
{
    private string $collectionHandle;

    protected function setUp(): void
    {
        parent::setUp();

        FieldtypeExtensions::flush();

        $this->collectionHandle = 'pages-' . bin2hex(random_bytes(4));
        Collection::make($this->collectionHandle)->title('Pages')->save();

        Blueprint::make('pages')->setNamespace("collections.{$this->collectionHandle}")->setContents([
            'fields' => [
                ['handle' => 'title', 'field' => ['type' => 'text']],
                ['handle' => 'keywords', 'field' => ['type' => 'list']],
            ],
        ])->save();

        Stache::refresh();
    }

    protected function tearDown(): void
    {
        FieldtypeExtensions::flush();

        parent::tearDown();
    }

    public function test_blueprint_get_serves_a_registered_spec(): void
    {
        $before = $this->fieldSpec();
        $this->assertArrayNotHasKey('_format_spec', $before);

        FieldtypeExtensions::spec('list', fn (Field $field): array => [
            'wire_format' => 'array',
            'shape' => 'string_list',
            'rules' => ['Array of plain strings.'],
        ]);

        $after = $this->fieldSpec();
        $this->assertSame('string_list', $after['_format_spec']['shape']);
    }

    public function test_a_registered_sanitizer_runs_on_write(): void
    {
        FieldtypeExtensions::sanitizer('list', function (mixed $value): mixed {
            return is_array($value) ? array_map('strtoupper', $value) : $value;
        });

        $result = (new EntriesRouter)->execute([
            'action' => 'create',
            'collection' => $this->collectionHandle,
            'slug' => 'coerced',
            'data' => ['title' => 'Coerced', 'keywords' => ['alpha', 'beta']],
        ]);

        $this->assertTrue($result['success'], json_encode($result['errors'] ?? []));

        $entry = Entry::query()->where('slug', 'coerced')->first();
        $this->assertSame(['ALPHA', 'BETA'], $entry->get('keywords'));
    }

    public function test_a_registered_sanitizer_can_reject_a_bad_shape(): void
    {
        FieldtypeExtensions::sanitizer('list', function (mixed $value, Field $field, string $path): mixed {
            if (! is_array($value)) {
                throw new FieldFormatException("Field [{$path}] expects an array of strings.");
            }

            return $value;
        });

        $result = (new EntriesRouter)->execute([
            'action' => 'create',
            'collection' => $this->collectionHandle,
            'slug' => 'rejected',
            'data' => ['title' => 'Rejected', 'keywords' => 'not-an-array'],
        ]);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('[keywords] expects an array', implode(' ', $result['errors']));
        $this->assertNull(Entry::query()->where('slug', 'rejected')->first());
    }

    public function test_values_pass_through_untouched_without_a_registration(): void
    {
        $result = (new EntriesRouter)->execute([
            'action' => 'create',
            'collection' => $this->collectionHandle,
            'slug' => 'untouched',
            'data' => ['title' => 'Untouched', 'keywords' => ['alpha']],
        ]);

        $this->assertTrue($result['success'], json_encode($result['errors'] ?? []));
        $this->assertSame(['alpha'], Entry::query()->where('slug', 'untouched')->first()->get('keywords'));
    }

    /**
     * @return array<string, mixed>
     */
    private function fieldSpec(): array
    {
        $result = (new BlueprintsRouter)->execute([
            'action' => 'get',
            'namespace' => 'collections',
            'collection_handle' => $this->collectionHandle,
            'handle' => 'pages',
            'include_format_spec' => true,
        ]);

        foreach ($result['data']['blueprint']['fields'] ?? [] as $field) {
            if (($field['handle'] ?? null) === 'keywords') {
                return $field;
            }
        }

        $this->fail('keywords field missing from blueprint response: ' . json_encode($result));
    }
}

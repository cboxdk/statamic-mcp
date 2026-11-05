<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Tests\Feature\Routers;

use Cboxdk\StatamicMcp\Mcp\Tools\Routers\BlueprintsRouter;
use Cboxdk\StatamicMcp\Mcp\Tools\Routers\EntriesRouter;
use Cboxdk\StatamicMcp\Mcp\Tools\Routers\TermsRouter;
use Cboxdk\StatamicMcp\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Statamic\Addons\Manifest;
use Statamic\Facades\Blueprint;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;
use Statamic\Facades\Stache;
use Statamic\Facades\Taxonomy;
use Statamic\Facades\Term;
use Statamic\SeoPro\ServiceProvider;

/** Integration coverage against the real, optional statamic/seo-pro package. */
class SeoProWritesTest extends TestCase
{
    protected function getPackageProviders($app)
    {
        return [...parent::getPackageProviders($app), ...(
            class_exists(ServiceProvider::class) ? [ServiceProvider::class] : []
        )];
    }

    protected function getEnvironmentSetUp($app)
    {
        parent::getEnvironmentSetUp($app);
        if (class_exists(ServiceProvider::class)) {
            $app->make(Manifest::class)->manifest['statamic/seo-pro'] = [
                'id' => 'statamic/seo-pro', 'slug' => null, 'version' => '7.16.0',
                'namespace' => 'Statamic\\SeoPro', 'autoload' => 'src',
                'provider' => ServiceProvider::class,
            ];
        }
    }

    protected function setUp(): void
    {
        parent::setUp();
        if (! class_exists(ServiceProvider::class)) {
            $this->markTestSkipped('Install statamic/seo-pro to run the SEO integration suite.');
        }
    }

    public static function records(): array
    {
        return [['entries'], ['terms']];
    }

    private function fixture(string $kind): array
    {
        $handle = 'seo-' . bin2hex(random_bytes(4));
        $isEntry = $kind === 'entries';
        ($isEntry ? Collection::make($handle) : Taxonomy::make($handle))->title('SEO')->save();
        Blueprint::make('seo')->setNamespace(($isEntry ? 'collections.' : 'taxonomies.') . $handle)
            ->setContents(['fields' => [['handle' => 'title', 'field' => ['type' => 'text']]]])->save();
        Stache::refresh();
        $record = $isEntry ? Entry::make()->collection($handle) : Term::make()->taxonomy($handle);
        $record->slug('original')->data(['title' => 'Original', 'seo' => [
            'title' => 'Old title', 'description' => 'Keep me', 'og_title' => '@seo:title',
            'twitter_description' => '', 'robots_noarchive' => false, 'robots' => ['noindex', 'nofollow'],
        ]])->save();
        $this->assertSame('seo_pro', $record->blueprint()->field('seo')->type());

        return [$isEntry ? new EntriesRouter : new TermsRouter, [
            $isEntry ? 'collection' : 'taxonomy' => $handle, 'id' => $record->id(),
        ], $record];
    }

    private function storedSeo($router, array $args, string $kind): mixed
    {
        $result = $router->execute([...$args, 'action' => 'get']);
        $this->assertTrue($result['success'], json_encode($result));

        return $result['data'][$kind === 'entries' ? 'entry' : 'term']['data']['seo'];
    }

    #[DataProvider('records')]
    public function test_blueprint_describes_runtime_seo_field(string $kind): void
    {
        [$router, $args] = $this->fixture($kind);
        $isEntry = $kind === 'entries';
        $result = (new BlueprintsRouter)->execute([
            'action' => 'get', 'handle' => 'seo',
            'namespace' => $isEntry ? 'collections' : 'taxonomies',
            $isEntry ? 'collection_handle' : 'taxonomy_handle' => $args[$isEntry ? 'collection' : 'taxonomy'],
        ]);
        $this->assertTrue($result['success'], json_encode($result));
        $field = collect($result['data']['blueprint']['fields'])->firstWhere('handle', 'seo');
        $this->assertSame('object_or_false', $field['_format_spec']['wire_format']);
        $this->assertContains('title', $field['_format_spec']['handles']);
    }

    #[DataProvider('records')]
    public function test_partial_updates_preserve_other_values_and_round_trip(string $kind): void
    {
        [$router, $args, $record] = $this->fixture($kind);
        foreach ([['title' => 'New title'], ['title' => ['source' => 'custom', 'value' => 'New title']], ['enabled' => true, 'title' => ['source' => 'custom', 'value' => 'New title']]] as $seo) {
            $result = $router->execute([...$args, 'action' => 'update', 'data' => ['seo' => $seo]]);
            $this->assertTrue($result['success'], json_encode($result));
            $saved = $router->execute([...$args, 'action' => 'get']);
            $data = $saved['data'][$kind === 'entries' ? 'entry' : 'term']['data'];
            $this->assertSame('New title', $data['seo']['title']);
            $this->assertSame('Keep me', $data['seo']['description']);
            $this->assertSame('@seo:title', $data['seo']['og_title']);
            $this->assertFalse($data['seo']['robots_noarchive']);
            $this->assertSame('', $data['seo']['twitter_description']);
            $this->assertSame(['noindex', 'nofollow'], $data['seo']['robots']);
        }
        $roundTrip = $router->execute([...$args, 'action' => 'update', 'data' => ['seo' => $data['seo']]]);
        $this->assertTrue($roundTrip['success'], json_encode($roundTrip));
    }

    #[DataProvider('records')]
    public function test_only_explicit_disable_turns_seo_off(string $kind): void
    {
        [$router, $args, $record] = $this->fixture($kind);
        foreach ([false, ['enabled' => false]] as $seo) {
            $result = $router->execute([...$args, 'action' => 'update', 'data' => ['seo' => $seo]]);
            $this->assertTrue($result['success'], json_encode($result));
            $this->assertFalse($this->storedSeo($router, $args, $kind));
            $result = $router->execute([...$args, 'action' => 'update', 'data' => ['title' => 'Unrelated']]);
            $this->assertTrue($result['success'], json_encode($result));
            $this->assertFalse($this->storedSeo($router, $args, $kind));
            $result = $router->execute([...$args, 'action' => 'update', 'data' => ['seo' => ['title' => 'Enabled again']]]);
            $this->assertTrue($result['success'], json_encode($result));
            $this->assertSame('Enabled again', $this->storedSeo($router, $args, $kind)['title']);
        }
    }

    #[DataProvider('records')]
    public function test_invalid_payload_does_not_change_stored_seo(string $kind): void
    {
        [$router, $args, $record] = $this->fixture($kind);
        $original = $this->storedSeo($router, $args, $kind);
        foreach (['invalid', null, ['enabled' => 'false'], ['unknown' => 'no'], ['title' => ['invalid']], ['title' => ['source' => 'custom', 'value' => ['invalid']]], ['title' => ['source' => 'invalid', 'value' => 'no']]] as $seo) {
            $result = $router->execute([...$args, 'action' => 'update', 'data' => ['seo' => $seo]]);
            $this->assertFalse($result['success'], json_encode($result));
            $this->assertSame($original, $this->storedSeo($router, $args, $kind));
        }
    }

    #[DataProvider('records')]
    public function test_inheritance_and_source_disable_preserve_other_fields(string $kind): void
    {
        [$router, $args, $record] = $this->fixture($kind);
        $result = $router->execute([...$args, 'action' => 'update', 'data' => ['seo' => ['title' => false, 'og_title' => null]]]);
        $this->assertTrue($result['success'], json_encode($result));
        $seo = $this->storedSeo($router, $args, $kind);
        $this->assertArrayNotHasKey('og_title', $seo);
        $this->assertSame('Keep me', $seo['description']);
        $this->assertFalse($seo['title']);
    }

    #[DataProvider('records')]
    public function test_create_accepts_flat_seo_and_explicit_disable(string $kind): void
    {
        [$router, $args] = $this->fixture($kind);
        unset($args['id']);
        $cases = [
            [['title' => 'SEO title', 'description' => 'Description'], ['title' => 'SEO title', 'description' => 'Description']],
            [false, false],
            [['title' => '', 'description' => '0', 'robots' => []], ['title' => '', 'description' => '0', 'robots' => []]],
            [['enabled' => false], false],
            // An empty SEO object inherits defaults; Statamic omits empty root arrays.
            [[], null],
            [['title' => ['source' => 'custom', 'value' => '']], ['title' => '']],
        ];
        foreach ($cases as $index => [$seo, $expected]) {
            $result = $router->execute([...$args, 'action' => 'create', 'slug' => 'new-' . $index, 'data' => ['title' => 'New', 'seo' => $seo]]);
            $this->assertTrue($result['success'], json_encode($result));
            $record = $kind === 'entries' ? Entry::query()->where('slug', 'new-' . $index)->first() : Term::query()->where('slug', 'new-' . $index)->first();
            $actual = $record->get('seo');
            if (is_array($expected) && is_array($actual)) {
                ksort($expected);
                ksort($actual);
            }
            $this->assertSame($expected, $actual);
        }
    }

    #[DataProvider('records')]
    public function test_stored_seo_round_trips_without_losing_empty_values(string $kind): void
    {
        [$router, $args] = $this->fixture($kind);
        $before = $this->storedSeo($router, $args, $kind);
        $result = $router->execute([...$args, 'action' => 'update', 'data' => ['seo' => $before]]);
        $this->assertTrue($result['success'], json_encode($result));
        $after = $this->storedSeo($router, $args, $kind);
        ksort($before);
        ksort($after);
        $this->assertSame($before, $after);
    }

    #[DataProvider('records')]
    public function test_empty_values_are_explicit_overrides_and_null_restores_inheritance(string $kind): void
    {
        [$router, $args] = $this->fixture($kind);
        $result = $router->execute([...$args, 'action' => 'update', 'data' => ['seo' => [
            'title' => '', 'description' => '0', 'robots' => [],
        ]]]);
        $this->assertTrue($result['success'], json_encode($result));
        $seo = $this->storedSeo($router, $args, $kind);
        $this->assertSame('', $seo['title']);
        $this->assertSame('0', $seo['description']);
        $this->assertSame([], $seo['robots']);
        $this->assertSame('@seo:title', $seo['og_title']);

        $result = $router->execute([...$args, 'action' => 'update', 'data' => ['seo' => [
            'title' => null, 'description' => null, 'robots' => null,
        ]]]);
        $this->assertTrue($result['success'], json_encode($result));
        $seo = $this->storedSeo($router, $args, $kind);
        foreach (['title', 'description', 'robots'] as $handle) {
            $this->assertArrayNotHasKey($handle, $seo);
        }
        $this->assertSame('@seo:title', $seo['og_title']);
    }

    #[DataProvider('records')]
    public function test_create_without_seo_leaves_it_unset(string $kind): void
    {
        [$router, $args] = $this->fixture($kind);
        unset($args['id']);
        $result = $router->execute([...$args, 'action' => 'create', 'slug' => 'no-seo', 'data' => ['title' => 'No SEO payload']]);
        $this->assertTrue($result['success'], json_encode($result));
        $record = $kind === 'entries' ? Entry::query()->where('slug', 'no-seo')->first() : Term::query()->where('slug', 'no-seo')->first();
        $this->assertArrayNotHasKey('seo', $record->data()->all());
    }
}

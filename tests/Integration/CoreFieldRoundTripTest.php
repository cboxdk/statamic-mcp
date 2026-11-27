<?php

declare(strict_types=1);

use Cboxdk\StatamicMcp\Mcp\Support\FieldFormatSpec;
use Cboxdk\StatamicMcp\Mcp\Tools\Routers\EntriesRouter;
use Statamic\Facades\Blueprint;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;
use Statamic\Fields\Field;
use Statamic\Fieldtypes\Bard\Augmentor;
use Tiptap\Core\Extension;
use Tiptap\Marks\Highlight;

it('writes and replays core field formats without losing values', function (array $config, mixed $input, mixed $stored): void {
    Collection::make('examples')->title('Examples')->save();
    Blueprint::make('example')->setNamespace('collections.examples')->setContents([
        'fields' => [
            ['handle' => 'title', 'field' => ['type' => 'text']],
            ['handle' => 'value', 'field' => $config],
        ],
    ])->save();
    $spec = (new FieldFormatSpec)->for(new Field('value', $config));
    $router = new EntriesRouter;
    $created = $router->execute(['action' => 'create', 'collection' => 'examples', 'data' => ['title' => 'Example', 'value' => $input]]);
    expect($created['success'])->toBeTrue(json_encode($created));
    $id = $created['data']['entry']['id'];
    $read = $router->execute(['action' => 'get', 'collection' => 'examples', 'id' => $id]);
    expect($read['data']['entry']['data']['value'])->toBe($stored);
    $updated = $router->execute(['action' => 'update', 'collection' => 'examples', 'id' => $id,
        'data' => ['value' => $read['data']['entry']['data']['value']]]);
    expect($updated['success'])->toBeTrue(json_encode($updated));
    // Stored values also pass validation when only an unrelated field changes.
    $unrelated = $router->execute(['action' => 'update', 'collection' => 'examples', 'id' => $id, 'data' => ['title' => 'Renamed']]);
    expect($unrelated['success'])->toBeTrue(json_encode($unrelated));
    $read = $router->execute(['action' => 'get', 'collection' => 'examples', 'id' => $id]);
    expect($read['data']['entry']['data']['value'])->toBe($stored);
    expect($spec)->not->toBeNull();
})->with([
    'dictionary single' => [['type' => 'dictionary', 'dictionary' => 'countries', 'max_items' => 1], 'DNK', 'DNK'],
    'dictionary multiple' => [['type' => 'dictionary', 'dictionary' => 'countries'], ['DNK', 'JPN'], ['DNK', 'JPN']],
    'range integer' => [['type' => 'range'], 23, 23],
    'range decimal' => [['type' => 'range', 'min' => -2.5, 'step' => .5], 1.5, 1.5],
    'width' => [['type' => 'width'], 33, 33],
    'list' => [['type' => 'list'], ['First', 23, 'Last'], ['First', 23, 'Last']],
    'array' => [['type' => 'array'], ['first' => 'One', 'second' => 23], ['first' => 'One', 'second' => 23]],
    'numeric array keys' => [['type' => 'array'], ['One', 'Two'], ['One', 'Two']],
    'expanded array' => [['type' => 'array', 'expand' => true], ['first' => 'One', 'second' => ['value' => 23, 'hidden' => true]], [['key' => 'first', 'value' => 'One'], ['key' => 'second', 'value' => 23, 'hidden' => true]]],
    'numeric expanded array' => [['type' => 'array', 'expand' => true], [['value' => 'One', 'hidden' => true]], [['key' => 0, 'value' => 'One', 'hidden' => true]]],
    'keyed expanded array' => [['type' => 'array', 'expand' => true, 'keys' => ['first' => 'First']], ['first' => ['value' => 'One', 'hidden' => true]], [['key' => 'first', 'value' => 'One', 'hidden' => true]]],
    'collections' => [['type' => 'collections'], ['examples'], ['examples']],
    'navs' => [['type' => 'navs'], ['main'], ['main']],
    'sites' => [['type' => 'sites'], ['en'], ['en']],
    'form' => [['type' => 'form', 'max_items' => 1], ['contact'], 'contact'],
    'form without max' => [['type' => 'form'], ['contact'], ['contact']],
    'roles' => [['type' => 'user_roles'], ['editor'], ['editor']],
    'groups' => [['type' => 'user_groups'], ['editors'], ['editors']],
    'link' => [['type' => 'link'], '/contact', '/contact'],
    'date range' => [['type' => 'date', 'mode' => 'range', 'format' => 'Y-m-d'], ['start' => '2026-01-03', 'end' => '2026-02-15'], ['start' => '2026-01-03', 'end' => '2026-02-15']],
    'inline bard' => [['type' => 'bard', 'inline' => true], [['type' => 'text', 'text' => 'Hello']], [['type' => 'text', 'text' => 'Hello']]],
    'block bard' => [['type' => 'bard'], [['type' => 'blockquote', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Quote']]]]]], [['type' => 'blockquote', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Quote']]]]]]],
]);

it('refuses malformed Bard nodes before storing an entry', function (array $nodes): void {
    Collection::make('examples')->save();
    Blueprint::make('example')->setNamespace('collections.examples')->setContents([
        'fields' => [['handle' => 'title', 'field' => ['type' => 'text']], ['handle' => 'body', 'field' => ['type' => 'bard']]],
    ])->save();
    $result = (new EntriesRouter)->execute(['action' => 'create', 'collection' => 'examples', 'data' => ['title' => 'Example', 'body' => $nodes]]);
    expect($result['success'])->toBeFalse(json_encode($result));
    expect(Entry::query()->where('collection', 'examples')->count())->toBe(0);
})->with([
    'unknown nested node' => [[['type' => 'paragraph', 'content' => [['type' => 'madeUp', 'text' => 'Lost']]]]],
    'unknown mark' => [[['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Lost', 'marks' => [['type' => 'madeUp']]]]]]],
    'missing text' => [[['type' => 'paragraph', 'content' => [['type' => 'text']]]]],
    'invalid content' => [[['type' => 'paragraph', 'content' => 'Lost']]],
    'nested set' => [[['type' => 'blockquote', 'content' => [['type' => 'set', 'attrs' => ['values' => ['type' => 'anything']]]]]]],
]);

it('publishes Bard examples that survive create and get, including native default buttons', function (bool $inline): void {
    Collection::make('examples')->save();
    $config = ['type' => 'bard', 'inline' => $inline];
    Blueprint::make('example')->setNamespace('collections.examples')->setContents([
        'fields' => [['handle' => 'title', 'field' => ['type' => 'text']], ['handle' => 'body', 'field' => $config]],
    ])->save();
    $spec = (new FieldFormatSpec)->for(new Field('body', $config));
    expect($spec['allowed_marks'])->toContain('bold', 'italic', 'link');
    if (! $inline) {
        expect($spec['allowed_node_types'])->toContain('text', 'blockquote', 'codeBlock', 'horizontalRule', 'table', 'tableRow', 'tableCell');
        expect($spec['allowed_heading_levels'])->toBe([2, 3]);
    }
    $created = (new EntriesRouter)->execute(['action' => 'create', 'collection' => 'examples', 'data' => ['title' => 'Example', 'body' => $spec['example']]]);
    expect($created['success'])->toBeTrue(json_encode($created));
    expect($created['data']['entry']['data']['body'])->toBe($inline
        ? [['type' => 'text', 'text' => 'Hello world']]
        : [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Body paragraph']]]]);
})->with([false, true]);

it('refuses malformed handles without clearing stored relationships', function (): void {
    Collection::make('examples')->save();
    Blueprint::make('example')->setNamespace('collections.examples')->setContents([
        'fields' => [['handle' => 'value', 'field' => ['type' => 'navs']]],
    ])->save();
    $entry = Entry::make()->id('handles')->collection('examples')->slug('handles')->data(['value' => ['main']]);
    $entry->save();
    foreach ([false, 123, [false]] as $value) {
        $result = (new EntriesRouter)->execute(['action' => 'update', 'collection' => 'examples', 'id' => 'handles', 'data' => ['value' => $value]]);
        expect($result['success'])->toBeFalse(json_encode($result));
        expect(Entry::find('handles')->get('value'))->toBe(['main']);
    }
});

it('accepts marks registered through native Bard extension bundles', function (): void {
    Augmentor::addExtension('test_bundle', new class extends Extension
    {
        public function addExtensions(): array
        {
            return [new Highlight];
        }
    });
    try {
        Collection::make('examples')->save();
        $config = ['type' => 'bard'];
        Blueprint::make('example')->setNamespace('collections.examples')->setContents([
            'fields' => [['handle' => 'body', 'field' => $config]],
        ])->save();
        expect((new FieldFormatSpec)->for(new Field('body', $config))['registered_marks'])->toContain('highlight');
        $body = [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Highlighted', 'marks' => [['type' => 'highlight']]]]]];
        $result = (new EntriesRouter)->execute(['action' => 'create', 'collection' => 'examples', 'slug' => 'highlighted', 'data' => ['title' => 'Highlighted', 'body' => $body]]);
        expect($result['success'])->toBeTrue(json_encode($result));
        expect($result['data']['entry']['data']['body'])->toBe($body);
    } finally {
        Augmentor::addExtension('test_bundle', null);
    }
});

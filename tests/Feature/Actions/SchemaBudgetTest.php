<?php

declare(strict_types=1);

use Cboxdk\StatamicMcp\Actions\SchemaValidator;

test('registered actions accept a ten MiB asset upload without inheriting the result budget', function () {
    $validator = app(SchemaValidator::class);
    $input = ['file' => base64_encode(str_repeat('a', 10 * 1024 * 1024))];
    $schema = ['type' => 'object', 'properties' => ['file' => ['type' => 'string']], 'required' => ['file']];
    $validator->validateInput($input, $schema);
    expect(fn () => $validator->validate($input, $schema))->toThrow(InvalidArgumentException::class);
});

test('action input respects the configured byte value and depth budgets before schema processing', function ($config, $value) {
    config()->set($config);
    expect(fn () => app(SchemaValidator::class)->validateInput($value, ['type' => 'object']))
        ->toThrow(InvalidArgumentException::class);
})->with([
    'bytes' => [['statamic.mcp.security.max_input_bytes' => 16], ['content' => 'large private value']],
    'values' => [['statamic.mcp.security.max_input_values' => 2], ['content' => [1, 2]]],
    'depth' => [['statamic.mcp.security.max_input_depth' => 1], ['content' => ['nested' => true]]],
]);

test('deep content below the configured nesting limit is accepted', function () {
    $input = ['value' => 'leaf'];
    for ($i = 0; $i < 40; $i++) {
        $input = ['nested' => $input];
    }
    app(SchemaValidator::class)->validateInput($input, ['type' => 'object']);
    expect(true)->toBeTrue();
});

test('output validation refuses executable serializers before calling them', function () {
    $called = false;
    $object = new class($called) implements JsonSerializable
    {
        public function __construct(private bool &$called) {}

        public function jsonSerialize(): mixed
        {
            $this->called = true;

            return 'not JSON storage data';
        }
    };
    expect(fn () => app(SchemaValidator::class)->validate(['content' => $object], ['type' => 'object']))
        ->toThrow(InvalidArgumentException::class);
    expect($called)->toBeFalse();
});

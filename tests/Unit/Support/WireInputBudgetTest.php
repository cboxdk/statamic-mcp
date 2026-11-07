<?php

declare(strict_types=1);

use Cboxdk\StatamicMcp\Support\WireInputBudget;

it('counts keys and values without treating string contents as structure', function () {
    $json = json_encode(['quoted' => '"[{}]\\', 'number' => -1.25e30, 'flags' => [true, false, null]], JSON_THROW_ON_ERROR);
    (new WireInputBudget(maxTokens: 10))->check($json);
    expect(fn () => (new WireInputBudget(maxTokens: 9))->check($json))->toThrow(LengthException::class);
});

it('refuses broad JSON before decoding its object graph', function () {
    expect(fn () => (new WireInputBudget(maxTokens: 20))->check('[' . str_repeat('0,', 20) . '0]'))->toThrow(LengthException::class);
});

it('refuses malformed and deeply nested JSON', function (string $json) {
    expect(fn () => (new WireInputBudget(maxDepth: 8))->check($json))->toThrow(InvalidArgumentException::class);
})->with(['unterminated' => '{"x":"', 'trailing' => '{}{}', 'nested' => str_repeat('[', 9) . '0' . str_repeat(']', 9)]);

it('accepts a default size asset upload and rejects a larger raw frame', function () {
    (new WireInputBudget)->check(json_encode(['content' => base64_encode(str_repeat('x', 10 * 1024 * 1024))], JSON_THROW_ON_ERROR));
    expect(fn () => (new WireInputBudget(maxBytes: 3))->check('null'))->toThrow(LengthException::class);
});

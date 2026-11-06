<?php

declare(strict_types=1);

use Cboxdk\StatamicMcp\Support\InputBudget;

it('bounds deeply nested input before field processing', function () {
    $input = ['value' => 'leaf'];
    for ($i = 0; $i < 80; $i++) {
        $input = ['child' => $input];
    }
    expect(fn () => (new InputBudget)->check($input))->toThrow(InvalidArgumentException::class);
});

it('bounds large strings without serializing a second full copy', function () {
    expect(fn () => (new InputBudget(100))->check(['value' => str_repeat('x', 101)]))->toThrow(InvalidArgumentException::class);
});

it('bounds broad arrays and rejects null bytes in keys', function () {
    expect(fn () => (new InputBudget(maxValues: 3))->check(['items' => [1, 2, 3]]))->toThrow(InvalidArgumentException::class);
    expect(fn () => (new InputBudget)->check(["bad\0key" => 'value']))->toThrow(InvalidArgumentException::class);
});

it('accepts ordinary nested content and empty values', function () {
    (new InputBudget)->check(['title' => '', 'items' => [['id' => 'one', 'enabled' => false]], 'optional' => null]);
    expect(true)->toBeTrue();
});

it('allows base64 expansion of an upload within the existing 10 MiB default', function () {
    (new InputBudget)->check(['content' => base64_encode(str_repeat('x', 10 * 1024 * 1024)), 'encoding' => 'base64']);
    expect(true)->toBeTrue();
});

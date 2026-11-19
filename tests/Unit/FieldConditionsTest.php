<?php

declare(strict_types=1);

use Cboxdk\StatamicMcp\Mcp\Support\FieldConditions;

/**
 * @param  array<string, mixed>  $config
 * @param  array<string, mixed>  $values
 */
function shown(array $config, array $values, array $revealers = []): bool
{
    return FieldConditions::passes($config, [$values], $revealers);
}

it('shows a field without conditions', function () {
    expect(shown([], ['a' => 1]))->toBeTrue();
});

it('evaluates equals and its aliases', function (string $rhs) {
    expect(shown(['if' => ['template' => $rhs]], ['template' => 'Brochure']))->toBeTrue()
        ->and(shown(['if' => ['template' => $rhs]], ['template' => 'team/index']))->toBeFalse()
        ->and(shown(['if' => ['template' => $rhs]], []))->toBeFalse();
})->with(['equals Brochure', 'is Brochure', '== Brochure', 'Brochure']);

it('evaluates not', function () {
    expect(shown(['if' => ['t' => 'not Brochure']], ['t' => 'x']))->toBeTrue()
        ->and(shown(['if' => ['t' => 'isnt Brochure']], ['t' => 'Brochure']))->toBeFalse();
});

it('inverts for unless and hide_when', function () {
    expect(shown(['unless' => ['t' => 'equals a']], ['t' => 'a']))->toBeFalse()
        ->and(shown(['hide_when' => ['t' => 'equals a']], ['t' => 'b']))->toBeTrue();
});

it('requires all conditions for if and any for if_any', function () {
    $values = ['a' => 'x', 'b' => 'y'];

    expect(shown(['if' => ['a' => 'equals x', 'b' => 'equals z']], $values))->toBeFalse()
        ->and(shown(['if_any' => ['a' => 'equals x', 'b' => 'equals z']], $values))->toBeTrue();
});

it('compares booleans and literals the way the Control Panel does', function () {
    expect(shown(['if' => ['on' => 'equals true']], ['on' => true]))->toBeTrue()
        ->and(shown(['if' => ['on' => 'equals true']], ['on' => false]))->toBeFalse()
        ->and(shown(['if' => ['on' => true]], ['on' => true]))->toBeTrue()
        ->and(shown(['if' => ['x' => 'equals null']], []))->toBeTrue()
        ->and(shown(['if' => ['x' => 'equals null']], ['x' => '']))->toBeTrue();
});

it('handles empty', function () {
    expect(shown(['if' => ['x' => 'empty']], ['x' => []]))->toBeTrue()
        ->and(shown(['if' => ['x' => 'not empty']], ['x' => ['a']]))->toBeTrue()
        ->and(shown(['if' => ['x' => 'not empty']], ['x' => null]))->toBeFalse();
});

it('handles numeric comparisons', function () {
    expect(shown(['if' => ['n' => '> 3']], ['n' => 5]))->toBeTrue()
        ->and(shown(['if' => ['n' => '<= 3']], ['n' => '5']))->toBeFalse()
        ->and(shown(['if' => ['n' => '> 3']], ['n' => 'abc']))->toBeFalse();
});

it('handles contains, contains_any and includes_any', function () {
    expect(shown(['if' => ['tags' => 'contains news']], ['tags' => ['news', 'blog']]))->toBeTrue()
        ->and(shown(['if' => ['s' => 'contains foo']], ['s' => 'a foo b']))->toBeTrue()
        ->and(shown(['if' => ['tags' => 'contains_any x, blog']], ['tags' => ['news', 'blog']]))->toBeTrue()
        ->and(shown(['if' => ['t' => 'includes_any a, b']], ['t' => 'c']))->toBeFalse();
});

it('resolves root and parent references', function () {
    $scopes = [['mode' => 'advanced'], ['kind' => 'url']];

    expect(FieldConditions::passes(['if' => ['$root.mode' => 'equals advanced']], $scopes))->toBeTrue()
        ->and(FieldConditions::passes(['if' => ['$parent.mode' => 'equals basic']], $scopes))->toBeFalse()
        ->and(FieldConditions::passes(['if' => ['kind' => 'equals url']], $scopes))->toBeTrue();
});

it('treats custom and revealer conditions as passing so validation is never loosened', function () {
    expect(shown(['if' => 'custom isAdmin'], []))->toBeTrue()
        ->and(shown(['if' => ['x' => 'custom isAdmin']], []))->toBeTrue()
        ->and(shown(['if' => ['reveal' => 'equals true']], [], ['reveal']))->toBeTrue();
});

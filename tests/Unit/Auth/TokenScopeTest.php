<?php

declare(strict_types=1);

use Cboxdk\StatamicMcp\Auth\TokenScope;

// ---------------------------------------------------------------------------
// 1. All 21 cases exist with correct string values
// ---------------------------------------------------------------------------

it('has exactly 21 cases', function (): void {
    expect(TokenScope::cases())->toHaveCount(21);
});

it('maps every case to its correct string value', function (TokenScope $scope, string $expected): void {
    expect($scope->value)->toBe($expected);
})->with([
    'FullAccess' => [TokenScope::FullAccess, '*'],
    'ContentRead' => [TokenScope::ContentRead, 'content:read'],
    'ContentWrite' => [TokenScope::ContentWrite, 'content:write'],
    'StructuresRead' => [TokenScope::StructuresRead, 'structures:read'],
    'StructuresWrite' => [TokenScope::StructuresWrite, 'structures:write'],
    'AssetsRead' => [TokenScope::AssetsRead, 'assets:read'],
    'AssetsWrite' => [TokenScope::AssetsWrite, 'assets:write'],
    'UsersRead' => [TokenScope::UsersRead, 'users:read'],
    'UsersWrite' => [TokenScope::UsersWrite, 'users:write'],
    'SystemRead' => [TokenScope::SystemRead, 'system:read'],
    'SystemWrite' => [TokenScope::SystemWrite, 'system:write'],
    'BlueprintsRead' => [TokenScope::BlueprintsRead, 'blueprints:read'],
    'BlueprintsWrite' => [TokenScope::BlueprintsWrite, 'blueprints:write'],
    'EntriesRead' => [TokenScope::EntriesRead, 'entries:read'],
    'EntriesWrite' => [TokenScope::EntriesWrite, 'entries:write'],
    'TermsRead' => [TokenScope::TermsRead, 'terms:read'],
    'TermsWrite' => [TokenScope::TermsWrite, 'terms:write'],
    'GlobalsRead' => [TokenScope::GlobalsRead, 'globals:read'],
    'GlobalsWrite' => [TokenScope::GlobalsWrite, 'globals:write'],
    'ContentFacadeRead' => [TokenScope::ContentFacadeRead, 'content-facade:read'],
    'ContentFacadeWrite' => [TokenScope::ContentFacadeWrite, 'content-facade:write'],
]);

// ---------------------------------------------------------------------------
// 2. label() returns non-empty human-readable strings for all cases
// ---------------------------------------------------------------------------

it('returns a non-empty label for every case', function (TokenScope $scope): void {
    $label = $scope->label();

    expect($label)
        ->toBeString()
        ->not->toBeEmpty();
})->with(TokenScope::cases());

it('returns the correct label for each case', function (TokenScope $scope, string $expected): void {
    expect($scope->label())->toBe($expected);
})->with([
    'FullAccess' => [TokenScope::FullAccess, 'Full Access'],
    'ContentRead' => [TokenScope::ContentRead, 'Read Content'],
    'ContentWrite' => [TokenScope::ContentWrite, 'Write Content'],
    'StructuresRead' => [TokenScope::StructuresRead, 'Read Structures'],
    'StructuresWrite' => [TokenScope::StructuresWrite, 'Write Structures'],
    'AssetsRead' => [TokenScope::AssetsRead, 'Read Assets'],
    'AssetsWrite' => [TokenScope::AssetsWrite, 'Write Assets'],
    'UsersRead' => [TokenScope::UsersRead, 'Read Users'],
    'UsersWrite' => [TokenScope::UsersWrite, 'Write Users'],
    'SystemRead' => [TokenScope::SystemRead, 'Read System'],
    'SystemWrite' => [TokenScope::SystemWrite, 'Write System'],
    'BlueprintsRead' => [TokenScope::BlueprintsRead, 'Read Blueprints'],
    'BlueprintsWrite' => [TokenScope::BlueprintsWrite, 'Write Blueprints'],
    'EntriesRead' => [TokenScope::EntriesRead, 'Read Entries'],
    'EntriesWrite' => [TokenScope::EntriesWrite, 'Write Entries'],
    'TermsRead' => [TokenScope::TermsRead, 'Read Terms'],
    'TermsWrite' => [TokenScope::TermsWrite, 'Write Terms'],
    'GlobalsRead' => [TokenScope::GlobalsRead, 'Read Globals'],
    'GlobalsWrite' => [TokenScope::GlobalsWrite, 'Write Globals'],
    'ContentFacadeRead' => [TokenScope::ContentFacadeRead, 'Read Content Facade'],
    'ContentFacadeWrite' => [TokenScope::ContentFacadeWrite, 'Write Content Facade'],
]);

// ---------------------------------------------------------------------------
// 3. group() maps correctly
// ---------------------------------------------------------------------------

it('returns the correct group for each case', function (TokenScope $scope, string $expected): void {
    expect($scope->group())->toBe($expected);
})->with([
    'FullAccess' => [TokenScope::FullAccess, 'access'],
    'ContentRead' => [TokenScope::ContentRead, 'content'],
    'ContentWrite' => [TokenScope::ContentWrite, 'content'],
    'StructuresRead' => [TokenScope::StructuresRead, 'structures'],
    'StructuresWrite' => [TokenScope::StructuresWrite, 'structures'],
    'AssetsRead' => [TokenScope::AssetsRead, 'assets'],
    'AssetsWrite' => [TokenScope::AssetsWrite, 'assets'],
    'UsersRead' => [TokenScope::UsersRead, 'users'],
    'UsersWrite' => [TokenScope::UsersWrite, 'users'],
    'SystemRead' => [TokenScope::SystemRead, 'system'],
    'SystemWrite' => [TokenScope::SystemWrite, 'system'],
    'BlueprintsRead' => [TokenScope::BlueprintsRead, 'blueprints'],
    'BlueprintsWrite' => [TokenScope::BlueprintsWrite, 'blueprints'],
    'EntriesRead' => [TokenScope::EntriesRead, 'entries'],
    'EntriesWrite' => [TokenScope::EntriesWrite, 'entries'],
    'TermsRead' => [TokenScope::TermsRead, 'terms'],
    'TermsWrite' => [TokenScope::TermsWrite, 'terms'],
    'GlobalsRead' => [TokenScope::GlobalsRead, 'globals'],
    'GlobalsWrite' => [TokenScope::GlobalsWrite, 'globals'],
    'ContentFacadeRead' => [TokenScope::ContentFacadeRead, 'content-facade'],
    'ContentFacadeWrite' => [TokenScope::ContentFacadeWrite, 'content-facade'],
]);

// ---------------------------------------------------------------------------
// 4. all() returns all 21 cases
// ---------------------------------------------------------------------------

it('returns all 21 cases from all()', function (): void {
    $all = TokenScope::all();

    expect($all)
        ->toBeArray()
        ->toHaveCount(21)
        ->each->toBeInstanceOf(TokenScope::class);
});

it('returns the same cases from all() as from cases()', function (): void {
    expect(TokenScope::all())->toBe(TokenScope::cases());
});

// ---------------------------------------------------------------------------
// 5. tryFrom() works for valid scope strings and returns null for invalid
// ---------------------------------------------------------------------------

it('resolves valid scope strings via tryFrom', function (string $value): void {
    expect(TokenScope::tryFrom($value))->toBeInstanceOf(TokenScope::class);
})->with([
    '*',
    'content:read',
    'content:write',
    'structures:read',
    'structures:write',
    'assets:read',
    'assets:write',
    'users:read',
    'users:write',
    'system:read',
    'system:write',
    'blueprints:read',
    'blueprints:write',
    'entries:read',
    'entries:write',
    'terms:read',
    'terms:write',
    'globals:read',
    'globals:write',
    'content-facade:read',
    'content-facade:write',
]);

it('returns null from tryFrom for invalid scope strings', function (string $value): void {
    expect(TokenScope::tryFrom($value))->toBeNull();
})->with([
    'invalid',
    'content',
    'read',
    'content:delete',
    'admin:read',
    '',
    'Content:Read',
    'CONTENT:READ',
]);

// ---------------------------------------------------------------------------
// 6. Each domain has exactly a read+write pair
// ---------------------------------------------------------------------------

it('has exactly a read and write scope for each domain', function (): void {
    $domains = ['content', 'structures', 'assets', 'users', 'system', 'blueprints', 'entries', 'terms', 'globals', 'content-facade'];

    foreach ($domains as $domain) {
        $readScope = TokenScope::tryFrom("{$domain}:read");
        $writeScope = TokenScope::tryFrom("{$domain}:write");

        expect($readScope)
            ->not->toBeNull("Expected {$domain}:read scope to exist");
        expect($writeScope)
            ->not->toBeNull("Expected {$domain}:write scope to exist");
    }
});

it('groups domain scopes into 10 domains plus access', function (): void {
    $groups = array_unique(array_map(
        fn (TokenScope $scope): string => $scope->group(),
        TokenScope::cases(),
    ));

    sort($groups);

    expect($groups)->toBe([
        'access',
        'assets',
        'blueprints',
        'content',
        'content-facade',
        'entries',
        'globals',
        'structures',
        'system',
        'terms',
        'users',
    ]);
});

// ---------------------------------------------------------------------------
// 7. FullAccess value is exactly '*'
// ---------------------------------------------------------------------------

it('has FullAccess with the wildcard value', function (): void {
    expect(TokenScope::FullAccess->value)->toBe('*');
});

it('resolves the wildcard string to FullAccess', function (): void {
    expect(TokenScope::from('*'))->toBe(TokenScope::FullAccess);
});

it('places FullAccess in the access group', function (): void {
    expect(TokenScope::FullAccess->group())->toBe('access');
});

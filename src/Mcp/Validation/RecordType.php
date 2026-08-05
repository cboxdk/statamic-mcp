<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Mcp\Validation;

/**
 * The kinds of record a content validation sweep walks, in the order it walks them.
 */
enum RecordType: string
{
    case Entry = 'entry';
    case Term = 'term';
    case Global = 'global';
    case Navigation = 'navigation';

    /**
     * The scope name that selects this record type, which is plural because a
     * scope names a set of records rather than one.
     */
    public function scope(): string
    {
        return match ($this) {
            self::Entry => 'entries',
            self::Term => 'terms',
            self::Global => 'globals',
            self::Navigation => 'navigations',
        };
    }

    public static function fromScope(string $scope): ?self
    {
        foreach (self::cases() as $case) {
            if ($case->scope() === $scope) {
                return $case;
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    public static function scopes(): array
    {
        return array_map(fn (self $case): string => $case->scope(), self::cases());
    }
}

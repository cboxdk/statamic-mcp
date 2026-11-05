<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Contracts;

use Statamic\Contracts\Entries\Collection;
use Statamic\Contracts\Entries\Entry;

interface CollectionTreeEditor
{
    /** @return array<string, mixed> */
    public function read(Collection $collection, string $site): array;

    public function parent(Entry $entry): ?string;

    /** Validate the entry's proposed field values and parent before saving. */
    public function validate(Entry $entry, mixed $parent): void;

    /** Move a saved entry and its descendants, returning the persisted parent. */
    public function move(Entry $entry, mixed $parent): ?string;
}

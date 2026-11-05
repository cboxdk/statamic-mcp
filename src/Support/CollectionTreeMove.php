<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Support;

use Statamic\Structures\CollectionTree;

/** A validated candidate tree and the version it was built from. */
class CollectionTreeMove
{
    public function __construct(
        public readonly CollectionTree $original,
        public readonly ?CollectionTree $candidate,
        public readonly string|false|null $version,
        public readonly ?string $parent,
    ) {}
}

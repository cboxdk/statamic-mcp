<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Tests\Fixtures;

use Cboxdk\StatamicMcp\Testing\InteractsWithMcp;
use Cboxdk\StatamicMcp\Tests\TestCase;

/**
 * Composition site for the traits this package ships for consumers.
 *
 * The traits exist to be used from a host application's test suite, so nothing
 * inside this package would otherwise prove they compose cleanly. Analysing this
 * fixture catches a signature that only breaks once the trait is mixed in.
 */
class McpTraitComposition extends TestCase
{
    use InteractsWithMcp;
}

<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Tests\Fixtures\ExtraTools;

/**
 * An extra tool whose discoveryKeywords() throws, standing in for a faulty
 * package. Discovery must leave it out and keep working for everyone else.
 */
class BrokenKeywordsExtraRouter extends UnscopedExtraRouter
{
    /**
     * @return list<string>
     */
    public function discoveryKeywords(): array
    {
        throw new \RuntimeException('The package behind this tool is broken.');
    }
}

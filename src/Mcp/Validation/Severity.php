<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Mcp\Validation;

/**
 * How much a validation finding matters.
 */
enum Severity: string
{
    /** The content is broken: it fails a rule, or breaks at render. */
    case Error = 'error';

    /** The content still renders, but stores something the blueprint no longer knows about. */
    case Warning = 'warning';
}

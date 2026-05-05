<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Mcp\Exceptions;

use InvalidArgumentException;

/**
 * Thrown when MCP-supplied field data does not match the expected wire format.
 *
 * Messages from this class are considered safe for direct exposure to clients
 * because the content is curated by this addon (field paths, expected shape).
 * The base BaseStatamicTool error handler allow-lists this class so messages
 * survive the production sanitization step.
 */
class FieldFormatException extends InvalidArgumentException {}

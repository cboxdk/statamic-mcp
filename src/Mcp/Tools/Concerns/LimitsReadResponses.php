<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Mcp\Tools\Concerns;

use Cboxdk\StatamicMcp\Mcp\DataTransferObjects\ErrorCode;
use Cboxdk\StatamicMcp\Mcp\Exceptions\ToolFailure;

trait LimitsReadResponses
{
    /**
     * Projection runs after authorization and field policy. It returns explicit
     * path/value pairs, not a partial record whose sparse arrays could be saved
     * back as a replacement. Paths are relative to the result data envelope.
     *
     * @param  array<string, mixed>  $response
     * @param  array<string, mixed>  $arguments
     *
     * @return array<string, mixed>
     */
    protected function prepareReadResponse(array $response, array $arguments, int $maxBytes): array
    {
        if (($response['success'] ?? false) !== true || ! is_array($response['data'] ?? null)
            || $this->isWriteAction(is_string($arguments['action'] ?? null) ? $arguments['action'] : '')) {
            return $response;
        }
        $data = $response['data'];
        if (($arguments['action'] ?? '') === 'get' && isset($arguments['fields']) && ! $this->isWriteAction('get')) {
            $paths = $arguments['fields'];
            if (! is_array($paths) || ! array_is_list($paths) || $paths === [] || count($paths) > 50) {
                throw new ToolFailure(ErrorCode::Validation, 'fields must be a list of 1–50 dot paths into the result, such as entry.data.title.', 'fields');
            }
            $values = [];
            foreach ($paths as $path) {
                if (! is_string($path) || strlen($path) > 512 || preg_match('/^[^.\s*]+(?:\.[^.\s*]+)*$/uD', $path) !== 1) {
                    throw new ToolFailure(ErrorCode::Validation, 'Each field must be a dot path; array indices are zero-based, wildcards are not supported.', 'fields');
                }
                $value = $data;
                foreach (explode('.', $path) as $part) {
                    if (! is_array($value) || ! array_key_exists($part, $value)) {
                        throw new ToolFailure(ErrorCode::Validation, "Field path is absent or not readable: {$path}", 'fields');
                    }
                    $value = $value[$part];
                }
                $values[] = ['path' => $path, 'value' => $value];
            }
            $response['data'] = ['projection' => true, 'fields' => $values];

            return $response;
        }

        if ($maxBytes <= 0 || strlen((string) json_encode($response)) <= $maxBytes
            || ! isset($data['items'], $data['total']) || ! is_array($data['items']) || ! is_int($data['total'])) {
            return $response;
        }

        // Find the largest whole-record prefix that fits, including the cursor
        // and envelope. Never advance past a record that was not returned.
        $items = array_values($data['items']);
        // The handler binds cursors after denied input filters are removed.
        // Use that same effective query when shortening its page.
        $arguments = $this->filterInputFields($arguments);
        $offset = $this->getPaginationArgs($arguments)['offset'];
        $low = 1;
        $high = count($items) - 1;
        $best = null;
        while ($low <= $high) {
            $count = intdiv($low + $high, 2);
            $page = $response;
            $page['data'] = [
                ...$data,
                'items' => array_slice($items, 0, $count),
                'next_cursor' => $this->nextCursor($data['total'], $offset + $count, $arguments),
                'truncated' => true,
            ];
            if (strlen((string) json_encode($page)) <= $maxBytes) {
                $best = $page;
                $low = $count + 1;
            } else {
                $high = $count - 1;
            }
        }

        // An individual oversized record is a TOO_LARGE error with the tool's
        // recovery hint, never an empty page with a cursor that cannot progress.
        return $best ?? $response;
    }

    protected function responseSizeHint(): string
    {
        if ($this->getDomain() === 'blueprints') {
            return 'Use get with field to select one blueprint field or set, reduce max_format_depth, or select result paths with fields. For lists, include_fields=false omits field definitions.';
        }
        if ($this->declaredAction('get') !== null) {
            return 'Read one record with action=get and fields containing dot paths from the result root through the field to an array index or child key. A list never skips an oversized record.';
        }

        return parent::responseSizeHint();
    }
}

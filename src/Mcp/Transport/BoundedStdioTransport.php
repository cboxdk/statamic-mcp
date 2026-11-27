<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Mcp\Transport;

use Cboxdk\StatamicMcp\Support\WireInputBudget;
use Laravel\Mcp\Server\Transport\StdioTransport;

/** Newline framing with a fixed read size, including fragmented messages and EOF. */
final class BoundedStdioTransport extends StdioTransport
{
    public function __construct(private readonly WireInputBudget $budget)
    {
        parent::__construct();
    }

    public function run(): void
    {
        stream_set_blocking(STDIN, false);
        $pending = '';
        while (! feof(STDIN)) {
            $chunk = fread(STDIN, 8192);
            if ($chunk === false) {
                $this->reject('Cannot read MCP input.');

                return;
            }
            if ($chunk === '') {
                usleep(10000);

                continue;
            }
            $offset = 0;
            $length = strlen($chunk);
            while ($offset < $length) {
                $newline = strpos($chunk, "\n", $offset);
                $end = $newline === false ? $length : $newline;
                $fragment = substr($chunk, $offset, $end - $offset);
                if (strlen($pending) + strlen($fragment) > $this->budget->maxBytes) {
                    $this->reject('MCP request exceeds its input budget.');

                    return; // Never interpret the tail of an oversized frame as a new request.
                }
                $pending .= $fragment;
                $offset = $end + 1;
                if ($newline !== false) {
                    $this->receive($pending);
                    $pending = '';
                }
            }
        }
        if ($pending !== '') {
            $this->receive($pending);
        }
    }

    private function receive(string $message): void
    {
        try {
            $this->budget->check($message);
        } catch (\LengthException|\InvalidArgumentException) {
            $this->reject('Invalid MCP JSON request or input budget exceeded.');

            return;
        }
        if (is_callable($this->handler)) {
            ($this->handler)($message);
        }
    }

    private function reject(string $message): void
    {
        $this->send(json_encode(['jsonrpc' => '2.0', 'id' => null,
            'error' => ['code' => -32600, 'message' => $message]], JSON_THROW_ON_ERROR));
    }
}

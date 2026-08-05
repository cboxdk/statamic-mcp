<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Testing;

use Closure;
use Laravel\Mcp\Server\Contracts\Transport;

/**
 * A transport that records instead of transmitting.
 *
 * Lets a test resolve the server without a real stdio or HTTP connection, and
 * lets it assert on what the server tried to send.
 */
class FakeTransport implements Transport
{
    /** @var list<array{message: string, sessionId: string|null}> */
    public array $sent = [];

    public function onReceive(Closure $handler): void
    {
        // Nothing arrives on a fake transport.
    }

    public function run(): void
    {
        // Nothing to pump.
    }

    public function send(string $message, ?string $sessionId = null): void
    {
        $this->sent[] = ['message' => $message, 'sessionId' => $sessionId];
    }

    public function sessionId(): ?string
    {
        return 'fake-session';
    }

    public function stream(Closure $stream): void
    {
        // Streaming is a no-op; send() already captures what matters.
    }

    /**
     * Every message the server handed to this transport.
     *
     * @return list<string>
     */
    public function messages(): array
    {
        return array_map(fn (array $sent): string => $sent['message'], $this->sent);
    }
}

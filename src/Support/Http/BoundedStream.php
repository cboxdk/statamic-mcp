<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Support\Http;

use GuzzleHttp\Psr7\StreamDecoratorTrait;
use GuzzleHttp\Psr7\Utils;
use Psr\Http\Message\StreamInterface;

/** A download sink that stops cURL before the response can exhaust temporary storage. */
final class BoundedStream implements StreamInterface
{
    use StreamDecoratorTrait;

    private int $received = 0;

    public bool $exceeded = false;

    public function __construct(private readonly int $maxBytes)
    {
        $this->stream = Utils::streamFor(fopen('php://temp', 'w+'));
    }

    public function write(string $string): int
    {
        if (strlen($string) > $this->maxBytes - $this->received) {
            // Returning a short write makes libcurl abort immediately. Throwing
            // from a PHP callback can defer the exception until the transfer ends.
            $this->exceeded = true;

            return 0;
        }
        $written = $this->stream->write($string);
        $this->received += $written;

        return $written;
    }
}

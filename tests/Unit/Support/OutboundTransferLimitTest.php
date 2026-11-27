<?php

declare(strict_types=1);

use Cboxdk\StatamicMcp\Support\Http\FetchRefused;
use Cboxdk\StatamicMcp\Support\Http\OutboundFetcher;
use Symfony\Component\Process\Process;

it('interrupts actual curl transfers before the oversized body has downloaded', function (string $mode) {
    if (! extension_loaded('curl')) {
        $this->markTestSkipped('The safe HTTP transport requires cURL.');
    }
    $marker = sys_get_temp_dir() . '/mcp-transfer-' . bin2hex(random_bytes(6));
    $process = new Process([PHP_BINARY, __DIR__ . '/../../Support/outbound-server.php', $mode, $marker]);
    $process->start();
    try {
        $process->waitUntil(fn (string $type, string $output): bool => str_contains($output, "\n"));
        $url = 'http://' . trim($process->getOutput()) . '/';
        try {
            (new OutboundFetcher(blockPrivate: false))->get($url, 1024, 5);
            $this->fail('Expected an oversized download to be refused.');
        } catch (FetchRefused $e) {
            expect($e->reason)->toBe(FetchRefused::TOO_LARGE);
        }
        expect(file_exists($marker))->toBeFalse();
    } finally {
        $process->stop(0);
        if (file_exists($marker)) {
            unlink($marker);
        }
    }
})->with(['known', 'unknown']);

it('allows a real response smaller than the download limit', function () {
    $process = new Process([PHP_BINARY, __DIR__ . '/../../Support/outbound-server.php', 'small']);
    $process->start();
    try {
        $process->waitUntil(fn (string $type, string $output): bool => str_contains($output, "\n"));
        $result = (new OutboundFetcher(blockPrivate: false))->get('http://' . trim($process->getOutput()) . '/', 1024, 5);
        expect($result->body)->toBe(str_repeat('x', 16));
    } finally {
        $process->stop(0);
    }
});

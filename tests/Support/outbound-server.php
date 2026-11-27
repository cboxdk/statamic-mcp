<?php

// One-request loopback server for testing actual cURL transfer limits, without Http::fake().
$server = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
if ($server === false) {
    exit(1);
}
echo stream_socket_get_name($server, false), "\n";
flush();
$client = stream_socket_accept($server, 5);
if ($client === false) {
    exit(2);
}
while (($line = fgets($client)) !== false && trim($line) !== '') {
}
$small = ($argv[1] ?? '') === 'small';
$bytes = $small ? 16 : 64 * 1024 * 1024;
$length = ($argv[1] ?? '') === 'unknown' ? '' : "Content-Length: {$bytes}\r\n";
fwrite($client, "HTTP/1.1 200 OK\r\n{$length}Connection: close\r\n\r\n");
if ($small) {
    fwrite($client, str_repeat('x', 16));
} else {
    // Exceed socket buffers without timing assertions: a bounded client must
    // disconnect before the server can finish this body, even on a busy CI host.
    for ($i = 0; $i < 4096; $i++) {
        if (@fwrite($client, str_repeat('x', 16384)) === false) {
            break;
        }
        if ($i === 4095) {
            file_put_contents($argv[2], 'entire oversized body sent');
        }
    }
}
fclose($client);
fclose($server);

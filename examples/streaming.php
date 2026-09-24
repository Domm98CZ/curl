<?php declare(strict_types=1);

// Consume a body while it arrives. onResponse fires once the headers are in, onChunk for every
// piece of the body; the response returned by send() then carries headers only.
// Run: php examples/streaming.php

require __DIR__ . '/../vendor/autoload.php';

use Domm98CZ\Curl\RequestBuilder;
use Domm98CZ\Curl\Streaming\HttpStreamHandler;
use Psr\Http\Message\ResponseInterface;

$bytes = 0;
$chunks = 0;

$handler = new HttpStreamHandler(
    onChunk: function (string $chunk) use (&$bytes, &$chunks): void {
        $bytes += strlen($chunk);
        if (++$chunks % 100 === 0) {
            printf("%d chunks, %d bytes so far\n", $chunks, $bytes);
        }
    },
    onResponse: function (ResponseInterface $response): void {
        printf("HTTP %d, Content-Length %s\n", $response->getStatusCode(), $response->getHeaderLine('Content-Length'));
    },
);

$response = RequestBuilder::get('https://speed.cloudflare.com/__down?bytes=4000000')
    ->withTimeout(60)
    ->withStreamHandler($handler)
    ->send();

printf("done: HTTP %d, %d bytes in %d chunks, returned body length %d\n", $response->getStatusCode(), $bytes, $chunks, strlen((string) $response->getBody()));

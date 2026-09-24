<?php declare(strict_types=1);

// Stream a response body into a file. The target path only appears once the transfer completed;
// a transport failure leaves nothing behind, an HTTP error body (404, 500) is stored and reported
// through the status. The returned response carries headers only.
// Run: php examples/download.php

require __DIR__ . '/../vendor/autoload.php';

use Domm98CZ\Curl\RequestBuilder;

$path = sys_get_temp_dir() . '/denni_kurz.txt';

$response = RequestBuilder::get('https://www.cnb.cz/cs/financni-trhy/devizovy-trh/kurzy-devizoveho-trhu/kurzy-devizoveho-trhu/denni_kurz.txt')
    ->withTimeout(15)
    ->downloadTo($path);

printf("HTTP %d, %s, %d bytes written to %s\n", $response->getStatusCode(), $response->getHeaderLine('Content-Type'), filesize($path), $path);
echo strtok((string) file_get_contents($path), "\n"), "\n";

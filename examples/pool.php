<?php declare(strict_types=1);

// Several requests at once with a concurrency cap. Pool takes plain PSR-7 requests, so build them
// with Psr7\Request (method, URI, headers) or with RequestBuilder::getRequest().
// Run: php examples/pool.php

require __DIR__ . '/../vendor/autoload.php';

use Domm98CZ\Curl\Pool;
use Domm98CZ\Curl\Psr7\Request;

$ares = 'https://ares.gov.cz/ekonomicke-subjekty-v-be/rest/ekonomicke-subjekty/';
$requests = [];
foreach (['45274649', '00177041', '99999999'] as $companyNumber) {
    $requests[$companyNumber] = new Request('GET', $ares . $companyNumber, ['Accept' => 'application/json']);
}

$results = (new Pool())->send($requests, concurrency: 2);

foreach ($results as $companyNumber => $result) {
    if ($result instanceof \Throwable) {
        // transport failure or unsupported scheme; HTTP 4xx/5xx is a response, not an exception
        printf("%s: %s\n", $companyNumber, $result->getMessage());
        continue;
    }
    $json = json_decode((string) $result->getBody(), true);
    printf("%s: HTTP %d %s\n", $companyNumber, $result->getStatusCode(), $json['obchodniJmeno'] ?? $json['kod'] ?? '');
}

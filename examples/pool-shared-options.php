<?php declare(strict_types=1);

// One set of Options\* for the whole batch: timeouts, TLS, proxy, redirect policy. A stream handler
// or a TransferInfoCollector is per-transfer state and cannot be shared; Pool refuses them, use
// AsyncClient::sendAsync($request, $options) for those.
// Run: php examples/pool-shared-options.php

require __DIR__ . '/../vendor/autoload.php';

use Domm98CZ\Curl\Options\ConnectTimeout;
use Domm98CZ\Curl\Options\Timeout;
use Domm98CZ\Curl\Pool;
use Domm98CZ\Curl\Psr7\Request;
use Domm98CZ\Curl\RequestOptions;

$requests = [
    'rates' => new Request('GET', 'https://www.cnb.cz/cs/financni-trhy/devizovy-trh/kurzy-devizoveho-trhu/kurzy-devizoveho-trhu/denni_kurz.txt'),
    'cez' => new Request('GET', 'https://ares.gov.cz/ekonomicke-subjekty-v-be/rest/ekonomicke-subjekty/45274649'),
];

$shared = new RequestOptions([new ConnectTimeout(5), new Timeout(15)]);
$results = (new Pool())->send($requests, concurrency: 2, options: $shared);

foreach ($results as $key => $result) {
    printf("%s: %s\n", $key, $result instanceof \Throwable ? $result->getMessage() : 'HTTP ' . $result->getStatusCode());
}

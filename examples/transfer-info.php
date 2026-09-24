<?php declare(strict_types=1);

// Timing per request. A TransferInfoCollector accepts exactly one transfer, so create a new one for
// every request; reusing it throws.
// Run: php examples/transfer-info.php

require __DIR__ . '/../vendor/autoload.php';

use Domm98CZ\Curl\RequestBuilder;
use Domm98CZ\Curl\TransferInfoCollector;

foreach (['45274649', '00177041'] as $companyNumber) {
    $timing = new TransferInfoCollector();

    $response = RequestBuilder::get('https://ares.gov.cz/ekonomicke-subjekty-v-be/rest/ekonomicke-subjekty/' . $companyNumber)
        ->withHeader('Accept', 'application/json')
        ->withTransferInfo($timing)
        ->send();

    $info = $timing->get();
    printf(
        "%s: HTTP %d, TTFB %.0f ms, total %.0f ms, %d bytes from %s\n",
        $companyNumber,
        $response->getStatusCode(),
        $info->startTransferTimeMs,
        $info->totalTimeMs,
        $info->sizeDownload ?? 0,
        $info->primaryIp ?? '?',
    );
}

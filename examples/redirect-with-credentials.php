<?php declare(strict_types=1);

// A request carrying a credential header (Authorization, an API key, any header outside the
// safelist) is not redirected automatically: you get the 3xx back. Opt in per request when you
// trust every origin the redirect can point to.
// Run: php examples/redirect-with-credentials.php

require __DIR__ . '/../vendor/autoload.php';

use Domm98CZ\Curl\Options\RedirectPolicy;
use Domm98CZ\Curl\RequestBuilder;

// ares.gov.cz answers http:// with a 308 to https://
$url = 'http://ares.gov.cz/ekonomicke-subjekty-v-be/rest/ekonomicke-subjekty/45274649';

$blocked = RequestBuilder::get($url)->withHeader('X-Api-Key', 'demo')->send();
printf("with X-Api-Key:          HTTP %d, Location %s\n", $blocked->getStatusCode(), $blocked->getHeaderLine('Location'));

$followed = RequestBuilder::get($url)
    ->withHeader('X-Api-Key', 'demo')
    ->withOption(new RedirectPolicy(followWithCredentials: true))
    ->send();
printf("with X-Api-Key + opt-in: HTTP %d, %d bytes\n", $followed->getStatusCode(), strlen((string) $followed->getBody()));

$plain = RequestBuilder::get($url)->send();
printf("no credential header:    HTTP %d, %d bytes\n", $plain->getStatusCode(), strlen((string) $plain->getBody()));

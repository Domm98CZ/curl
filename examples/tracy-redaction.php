<?php declare(strict_types=1);

// Exceptions carry the request (PSR-18 requires getRequest()), and a debugger that renders the
// stack trace renders its credentials with it. Tracy's BlueScreen already hides `authorization`;
// add every other credential header to its keysToHide. The request keeps header values under their
// lower-case names, which is also the key a custom BlueScreen scrubber receives.
// Run: php examples/tracy-redaction.php

require __DIR__ . '/../vendor/autoload.php';

use Domm98CZ\Curl\Psr7\Request;
use Tracy\Debugger;
use Tracy\Dumper;

if (!class_exists(Debugger::class)) {
    echo "Tracy is not installed; run `composer require --dev tracy/tracy` to try this example.\n";
    exit(0);
}

$request = new Request('GET', 'https://api.example.com/users', [
    'Authorization' => 'Bearer secret-token',
    'X-Api-Key' => 'secret-key',
]);

// the same dumper settings a BlueScreen applies to the arguments of every frame
$blueScreen = Debugger::getBlueScreen();
$dump = static fn (): string => Dumper::toText(
    $request,
    [Dumper::DEPTH => 3, Dumper::KEYS_TO_HIDE => $blueScreen->keysToHide],
);

echo "Tracy defaults:\n", $dump(), "\n";

$blueScreen->keysToHide = [...$blueScreen->keysToHide, 'x-api-key', 'cookie', 'proxy-authorization'];

echo "With x-api-key, cookie and proxy-authorization added:\n", $dump(), "\n";

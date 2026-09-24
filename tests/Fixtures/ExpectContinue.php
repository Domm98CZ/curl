<?php declare(strict_types=1);

namespace Domm98CZ\Curl\Tests\Fixtures;

use Domm98CZ\Curl\Options\RawCurlOption;

// php -S never answers an Expect: 100-continue, so libcurl spends its whole wait on every request
// whose body made it add the header. Zeroing the wait leaves the request byte for byte the same on
// the wire; only the client stops waiting for an interim response this fixture server cannot send.
final class ExpectContinue
{
    public static function doNotWait(): RawCurlOption
    {
        return new RawCurlOption(CURLOPT_EXPECT_100_TIMEOUT_MS, 0);
    }
}

<?php declare(strict_types=1);

namespace Domm98CZ\Curl\Tests;

use PHPUnit\Framework\TestCase;

final class SmokeTest extends TestCase
{
    public function testPhpVersionIsWithinTheSupportedRange(): void
    {
        self::assertGreaterThanOrEqual(80100, PHP_VERSION_ID);
        self::assertLessThan(80600, PHP_VERSION_ID);
    }

    public function testCurlExtensionIsLoaded(): void
    {
        self::assertTrue(extension_loaded('curl'));
    }
}

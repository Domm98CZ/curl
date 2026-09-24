<?php declare(strict_types=1);

namespace Domm98CZ\Curl\Tests;

use Domm98CZ\Curl\Psr7\Stream;
use Domm98CZ\Curl\RawResponse;
use PHPUnit\Framework\TestCase;

final class RawResponseTest extends TestCase
{
    public function testSuccessfulTransferIsNotAnError(): void
    {
        $raw = new RawResponse(0, '', "HTTP/1.1 200 OK\r\n\r\n", new Stream(fopen('php://temp', 'r+')), []);
        self::assertFalse($raw->isTransportError());
        self::assertFalse($raw->responseSizeExceeded);
    }

    public function testNonZeroErrnoIsAnError(): void
    {
        $raw = new RawResponse(6, "Couldn't resolve host", '', new Stream(fopen('php://temp', 'r+')), []);
        self::assertTrue($raw->isTransportError());
        self::assertSame(6, $raw->errno);
        self::assertSame("Couldn't resolve host", $raw->error);
    }

}

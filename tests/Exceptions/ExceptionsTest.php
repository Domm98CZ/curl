<?php declare(strict_types=1);

namespace Domm98CZ\Curl\Tests\Exceptions;

use Domm98CZ\Curl\Contract\NonRetryableExceptionInterface;
use Domm98CZ\Curl\Exceptions\ClientException;
use Domm98CZ\Curl\Exceptions\NetworkException;
use Domm98CZ\Curl\Exceptions\RequestException;
use Domm98CZ\Curl\Exceptions\ResponseSizeException;
use Domm98CZ\Curl\Psr7\Request;
use Domm98CZ\Curl\Psr7\Stream;
use Domm98CZ\Curl\RawResponse;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\NetworkExceptionInterface;
use Psr\Http\Client\RequestExceptionInterface;

final class ExceptionsTest extends TestCase
{
    public function testNetworkExceptionImplementsPsr18Contracts(): void
    {
        $request = new Request('GET', 'https://example.com');
        $exception = new NetworkException('Connection refused', $request);

        self::assertInstanceOf(ClientExceptionInterface::class, $exception);
        self::assertInstanceOf(NetworkExceptionInterface::class, $exception);
        self::assertInstanceOf(ClientException::class, $exception);
        self::assertSame($request, $exception->getRequest());
        self::assertSame('Connection refused', $exception->getMessage());
    }

    public function testRequestExceptionImplementsPsr18Contracts(): void
    {
        $request = new Request('GET', 'https://example.com');
        $exception = new RequestException('Malformed request', $request);

        self::assertInstanceOf(ClientExceptionInterface::class, $exception);
        self::assertInstanceOf(RequestExceptionInterface::class, $exception);
        self::assertSame($request, $exception->getRequest());
    }

    public function testResponseSizeExceptionKeepsTheRequestWithoutClaimingANetworkFailure(): void
    {
        $request = new Request('GET', 'https://example.com');
        $message = 'response-size policy';
        $raw = new RawResponse(
            CURLE_WRITE_ERROR,
            $message,
            '',
            new Stream(fopen('php://temp', 'r+')),
            [],
            responseSizeExceeded: true,
        );

        $exception = ClientException::forTransportError($raw, $request);

        self::assertInstanceOf(ResponseSizeException::class, $exception);
        self::assertInstanceOf(ClientExceptionInterface::class, $exception);
        self::assertInstanceOf(NonRetryableExceptionInterface::class, $exception);
        self::assertNotInstanceOf(NetworkExceptionInterface::class, $exception);
        self::assertNotInstanceOf(RequestExceptionInterface::class, $exception);
        self::assertSame($request, $exception->getRequest());
        self::assertSame(CURLE_WRITE_ERROR, $exception->getCurlErrno());
        self::assertSame($message, $exception->getMessage());
    }

}

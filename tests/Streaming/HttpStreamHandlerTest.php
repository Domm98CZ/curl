<?php declare(strict_types=1);

namespace Domm98CZ\Curl\Tests\Streaming;

use Domm98CZ\Curl\Streaming\HttpStreamHandler;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;

final class HttpStreamHandlerTest extends TestCase
{
    public function testResetForRetryDiscardsAnIncompleteHeaderBlock(): void
    {
        $responses = [];
        $handler = new HttpStreamHandler(
            onChunk: static function (string $chunk): void {
            },
            onResponse: static function (ResponseInterface $response) use (&$responses): void {
                $responses[] = $response;
            },
            onRetry: static fn (): bool => true,
        );

        $handler->onHeaderLine("HTTP/1.1 503 Service Unavailable\r\n");
        $handler->onHeaderLine("X-Foo: 1\r\n");

        self::assertTrue($handler->resetForRetry());

        $handler->onHeaderLine("HTTP/1.1 200 OK\r\n");
        $handler->onHeaderLine("X-Bar: 2\r\n");
        $handler->onHeaderLine("\r\n");

        self::assertCount(1, $responses);
        self::assertSame(200, $responses[0]->getStatusCode());
        self::assertFalse($responses[0]->hasHeader('X-Foo'));
        self::assertSame('2', $responses[0]->getHeaderLine('X-Bar'));
    }
}

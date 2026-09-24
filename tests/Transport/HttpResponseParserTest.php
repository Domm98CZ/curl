<?php declare(strict_types=1);

namespace Domm98CZ\Curl\Tests\Transport;

use Domm98CZ\Curl\Exceptions\ClientException;
use Domm98CZ\Curl\Psr7\Stream;
use Domm98CZ\Curl\RawResponse;
use Domm98CZ\Curl\Transport\HttpResponseParser;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientExceptionInterface;

final class HttpResponseParserTest extends TestCase
{
    private HttpResponseParser $parser;

    protected function setUp(): void
    {
        $this->parser = new HttpResponseParser();
    }

    private function bodyStream(string $contents = ''): Stream
    {
        $resource = fopen('php://temp', 'r+');
        fwrite($resource, $contents);
        rewind($resource);
        return new Stream($resource);
    }

    public function testParsesStatusLineAndHeaders(): void
    {
        $raw = new RawResponse(
            0,
            '',
            "HTTP/1.1 200 OK\r\nContent-Type: application/json\r\n\r\n",
            $this->bodyStream('{"a":1}'),
            []
        );

        $response = $this->parser->parse($raw);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('OK', $response->getReasonPhrase());
        self::assertSame('application/json', $response->getHeaderLine('Content-Type'));
        self::assertSame('{"a":1}', (string) $response->getBody());
    }

    public function testParsesCustomReasonPhrase(): void
    {
        $raw = new RawResponse(0, '', "HTTP/1.1 404 Nothing Here\r\n\r\n", $this->bodyStream(), []);
        self::assertSame('Nothing Here', $this->parser->parse($raw)->getReasonPhrase());
    }

    public function testMultiValueHeadersAreAccumulated(): void
    {
        $raw = new RawResponse(
            0,
            '',
            "HTTP/1.1 200 OK\r\nSet-Cookie: a=1\r\nSet-Cookie: b=2\r\n\r\n",
            $this->bodyStream(),
            []
        );

        self::assertSame(['a=1', 'b=2'], $this->parser->parse($raw)->getHeader('Set-Cookie'));
    }

    public function testUsesLastHeaderBlockAfterRedirects(): void
    {
        $raw = new RawResponse(
            0,
            '',
            "HTTP/1.1 301 Moved Permanently\r\nLocation: /new\r\n\r\nHTTP/1.1 200 OK\r\nContent-Type: text/plain\r\n\r\n",
            $this->bodyStream('final body'),
            []
        );

        $response = $this->parser->parse($raw);
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('text/plain', $response->getHeaderLine('Content-Type'));
        self::assertFalse($response->hasHeader('Location'));
    }

    public function testHttp4xxAnd5xxProduceAnOrdinaryResponseNotAnException(): void
    {
        $raw = new RawResponse(0, '', "HTTP/1.1 500 Internal Server Error\r\n\r\n", $this->bodyStream(), []);
        $response = $this->parser->parse($raw);
        self::assertSame(500, $response->getStatusCode());
    }

    public function testEmptyHeaderBlockIsNotPassedOffAsAnHttp200(): void
    {
        $raw = new RawResponse(0, '', '', $this->bodyStream('e708eb168cb7'), []);

        $this->expectException(ClientException::class);
        $this->parser->parse($raw);
    }

    public function testGarbageStatusLineIsNotPassedOffAsAnHttp200(): void
    {
        $raw = new RawResponse(0, '', "NOT-HTTP nonsense\r\nX: 1\r\n\r\n", $this->bodyStream('junk'), []);

        $this->expectException(ClientException::class);
        $this->parser->parse($raw);
    }

    public function testTruncatedStatusLineIsNotPassedOffAsAnHttp200(): void
    {
        $raw = new RawResponse(0, '', "HTTP/1.1 20\r\n\r\n", $this->bodyStream(), []);

        $this->expectException(ClientException::class);
        $this->parser->parse($raw);
    }

    public function testAnUnparsableStatusLineFailsWithinThePsr18Contract(): void
    {
        $raw = new RawResponse(0, '', 'garbage', $this->bodyStream(), []);

        $this->expectException(ClientExceptionInterface::class);
        $this->parser->parse($raw);
    }

    public function testDropsContentEncodingBecauseTheBodyIsAlreadyDecoded(): void
    {
        $raw = new RawResponse(
            0,
            '',
            "HTTP/1.1 200 OK\r\nContent-Encoding: gzip\r\nContent-Length: 20\r\nContent-Type: text/plain\r\n\r\n",
            $this->bodyStream('decoded body text'),
            []
        );

        $response = $this->parser->parse($raw);

        self::assertFalse($response->hasHeader('Content-Encoding'));
        self::assertSame('17', $response->getHeaderLine('Content-Length'));
        self::assertSame('text/plain', $response->getHeaderLine('Content-Type'));
    }

    public function testKeepsIdentityContentEncodingAndItsContentLengthUntouched(): void
    {
        $raw = new RawResponse(
            0,
            '',
            "HTTP/1.1 200 OK\r\nContent-Encoding: identity\r\nContent-Length: 4\r\n\r\n",
            $this->bodyStream('body'),
            []
        );

        $response = $this->parser->parse($raw);

        self::assertSame('identity', $response->getHeaderLine('Content-Encoding'));
        self::assertSame('4', $response->getHeaderLine('Content-Length'));
    }

    public function testDoesNotRewriteContentLengthWhenTheBodyWasNotBuffered(): void
    {
        $raw = new RawResponse(
            0,
            '',
            "HTTP/1.1 200 OK\r\nContent-Encoding: gzip\r\nContent-Length: 20\r\n\r\n",
            $this->bodyStream(),
            [],
            bodyBuffered: false
        );

        $response = $this->parser->parse($raw);

        self::assertFalse($response->hasHeader('Content-Encoding'));
        self::assertFalse($response->hasHeader('Content-Length'));
    }

    public function testParseHeaderBlockBuildsAResponseWithoutABody(): void
    {
        $response = $this->parser->parseHeaderBlock("HTTP/1.1 200 OK\r\nContent-Type: text/event-stream\r\n");

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('text/event-stream', $response->getHeaderLine('Content-Type'));
        self::assertSame('', (string) $response->getBody());
    }

    public function testANonStandardStatusCodeFromTheWireIsAcceptedNotRejected(): void
    {
        $raw = new RawResponse(0, '', "HTTP/1.1 999 Request Denied\r\n\r\n", $this->bodyStream('denied'), []);

        $response = $this->parser->parse($raw);

        self::assertSame(999, $response->getStatusCode());
        self::assertSame('Request Denied', $response->getReasonPhrase());
    }

    public function testMalformedInboundHeaderNameIsSkippedNotThrown(): void
    {
        $raw = new RawResponse(
            0,
            '',
            "HTTP/1.1 200 OK\r\nX Powered By: php\r\nContent-Type: text/plain\r\n\r\n",
            $this->bodyStream(),
            []
        );

        $response = $this->parser->parse($raw);

        self::assertSame(200, $response->getStatusCode());
        self::assertFalse($response->hasHeader('X Powered By'));
        self::assertSame('text/plain', $response->getHeaderLine('Content-Type'));
    }
}

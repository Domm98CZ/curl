<?php declare(strict_types=1);

namespace Domm98CZ\Curl\Tests\Transport;

require_once __DIR__ . '/TransportFunctionHarness.php';

use Domm98CZ\Curl\Client;
use Domm98CZ\Curl\Exceptions\NetworkException;
use Domm98CZ\Curl\Exceptions\RequestException;
use Domm98CZ\Curl\Exceptions\ResponseBufferException;
use Domm98CZ\Curl\Options\ConnectTimeout;
use Domm98CZ\Curl\Options\Encoding;
use Domm98CZ\Curl\Options\RawCurlOption;
use Domm98CZ\Curl\Options\Timeout;
use Domm98CZ\Curl\Psr7\Request;
use Domm98CZ\Curl\Psr7\Stream;
use Domm98CZ\Curl\RequestOptions;
use Domm98CZ\Curl\Tests\Fixtures\ExpectContinue;
use Domm98CZ\Curl\Tests\Fixtures\TestServer;
use Domm98CZ\Curl\Tests\Fakes\UnvalidatedRequest;
use Domm98CZ\Curl\Transport\CurlOptionsMapper;
use Domm98CZ\Curl\Transport\CurlTransport;
use Domm98CZ\Curl\Transport\InterceptsTransportFunctions;
use Domm98CZ\Curl\TransferInfoCollector;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Message\StreamInterface;
use RuntimeException;

final class CurlTransportTest extends TestCase
{
    use InterceptsTransportFunctions;

    private static TestServer $server;
    private CurlTransport $transport;

    public static function setUpBeforeClass(): void
    {
        self::$server = new TestServer();
        self::$server->start();
    }

    public static function tearDownAfterClass(): void
    {
        self::$server->stop();
    }

    protected function setUp(): void
    {
        $this->transport = new CurlTransport();
    }

    public function testSuccessfulGetReturnsBodyAndHeaders(): void
    {
        $request = new Request('GET', self::$server->baseUrl . '/ok');
        $raw = $this->transport->execute($request, new RequestOptions());

        self::assertFalse($raw->isTransportError());
        self::assertSame('ok', (string) $raw->body);
        self::assertStringContainsString('X-Test: 1', $raw->headerRaw);
        self::assertStringContainsString('200', $raw->headerRaw);
    }

    public function testAnEmptyHeaderValueIsDeliveredToTheServer(): void
    {
        $request = (new Request('GET', self::$server->baseUrl . '/echo-x-foo'))
            ->withHeader('X-Foo', '');

        $raw = $this->transport->execute($request, new RequestOptions());

        self::assertFalse($raw->isTransportError());
        self::assertSame(
            ['present' => true, 'value' => ''],
            json_decode((string) $raw->body, true, flags: JSON_THROW_ON_ERROR),
        );
    }

    public function testAnExplicitAsteriskRequestTargetReachesTheWire(): void
    {
        self::$server->clearRequests();
        $request = (new Request('OPTIONS', self::$server->baseUrl . '/from-uri?query=1'))
            ->withRequestTarget('*');

        $raw = $this->transport->execute($request, new RequestOptions());

        self::assertFalse($raw->isTransportError());
        self::assertSame(['OPTIONS * HTTP/1.1'], self::$server->requests());
    }

    public function testACrlfRequestTargetIsRejectedBeforeAnythingReachesTheWire(): void
    {
        self::$server->clearRequests();
        $request = new UnvalidatedRequest(
            new Request('GET', self::$server->baseUrl . '/from-uri'),
            requestTarget: "/safe\r\nX-Injected: yes",
        );

        try {
            $this->transport->execute($request, new RequestOptions());
            self::fail('Expected the injected request target to be rejected.');
        } catch (RequestException $exception) {
            self::assertSame($request, $exception->getRequest());
        }

        self::assertSame([], self::$server->requests());
    }

    public function testBufferedWriteFailureIsReportedAsALocalBufferFailureKeepingItsCause(): void
    {
        $failure = new RuntimeException('buffered write failed');
        $interceptor = $this->interceptTransportFunctions()->failNextWrite($failure);
        $caught = null;

        try {
            $interceptor->whileArmed(fn () => $this->transport->execute(
                new Request('GET', self::$server->baseUrl . '/ok'),
                new RequestOptions(),
            ));
        } catch (\Throwable $exception) {
            $caught = $exception;
        }

        // The buffered branch runs no consumer code, so the identity promised to a StreamHandler's
        // own exception is not owed here; a bare RuntimeException escaping instead would leave a
        // library-caused failure uncatchable through ClientExceptionInterface.
        self::assertInstanceOf(ResponseBufferException::class, $caught);
        self::assertInstanceOf(ClientExceptionInterface::class, $caught);
        self::assertSame($failure, $caught->getPrevious());
    }

    public function testHttpErrorStatusIsNotATransportError(): void
    {
        $request = new Request('GET', self::$server->baseUrl . '/status/500');
        $raw = $this->transport->execute($request, new RequestOptions());

        self::assertFalse($raw->isTransportError());
        self::assertStringContainsString('500', $raw->headerRaw);
        self::assertSame('status-500', (string) $raw->body);
    }

    public function testPostBodyIsStreamedToServer(): void
    {
        $resource = fopen('php://temp', 'r+');
        fwrite($resource, 'request-payload');
        rewind($resource);

        $request = (new Request('POST', self::$server->baseUrl . '/echo'))->withBody(new Stream($resource));
        $raw = $this->transport->execute($request, new RequestOptions());

        self::assertSame('request-payload', (string) $raw->body);
    }

    public function testLargePostBodyIsSentAsPostThroughTheReadCallback(): void
    {
        $payload = str_repeat('a', CurlOptionsMapper::REPLAYABLE_BODY_LIMIT + 1);
        $request = (new Request('POST', self::$server->baseUrl . '/echo-request'))
            ->withBody($this->stream($payload));

        $raw = $this->transport->execute($request, new RequestOptions([ExpectContinue::doNotWait()]));

        self::assertFalse($raw->isTransportError());
        $received = json_decode((string) $raw->body, true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('POST', $received['method']);
        self::assertSame((string) strlen($payload), $received['contentLength']);
        self::assertSame('', $received['transferEncoding']);
        self::assertSame(strlen($payload), strlen($received['body']));
        self::assertSame(hash('sha256', $payload), hash('sha256', $received['body']));
    }

    // php -S never answers an Expect, so a buffered body that carried one would wait out libcurl's
    // whole interim timeout; the header itself is what the server reports back.
    public function testABufferedBodyReachesTheServerWithoutAnExpectHeader(): void
    {
        $request = (new Request('POST', self::$server->baseUrl . '/echo-request'))
            ->withBody($this->stream(str_repeat('a', 64 * 1024)));

        $started = microtime(true);
        $raw = $this->transport->execute($request, new RequestOptions());
        $elapsed = microtime(true) - $started;

        $received = json_decode((string) $raw->body, true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('', $received['expect']);
        self::assertSame(64 * 1024, strlen($received['body']));
        self::assertLessThan(0.9, $elapsed);
    }

    public function testAStreamedBodyStillAnnouncesExpect(): void
    {
        $payload = str_repeat('a', CurlOptionsMapper::REPLAYABLE_BODY_LIMIT + 1);
        $request = (new Request('POST', self::$server->baseUrl . '/echo-request'))
            ->withBody($this->stream($payload));

        $raw = $this->transport->execute($request, new RequestOptions([ExpectContinue::doNotWait()]));

        $received = json_decode((string) $raw->body, true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('100-continue', strtolower($received['expect']));
    }

    // php -S never answers Expect, so the old chunked framing of an empty POST cost a whole interim wait
    public function testAnEmptyPostIsSentWithAZeroContentLengthAndNoInterimWait(): void
    {
        $request = new Request('POST', self::$server->baseUrl . '/echo-request');

        $started = microtime(true);
        $raw = $this->transport->execute($request, new RequestOptions());
        $elapsed = microtime(true) - $started;

        self::assertFalse($raw->isTransportError());
        $received = json_decode((string) $raw->body, true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('POST', $received['method']);
        self::assertSame('0', $received['contentLength']);
        self::assertSame('', $received['transferEncoding']);
        self::assertSame('', $received['expect']);
        self::assertSame('', $received['contentType']);
        self::assertSame('', $received['body']);
        self::assertLessThan(0.5, $elapsed);
    }

    public function testAnEmptyPostKeepsAnExplicitContentType(): void
    {
        $request = new Request('POST', self::$server->baseUrl . '/echo-request', ['Content-Type' => 'application/json']);

        $raw = $this->transport->execute($request, new RequestOptions());

        self::assertFalse($raw->isTransportError());
        $received = json_decode((string) $raw->body, true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('application/json', $received['contentType']);
        self::assertSame('0', $received['contentLength']);
        self::assertSame('', $received['transferEncoding']);
    }

    #[DataProvider('dotSegmentPathProvider')]
    public function testDotSegmentsInThePathReachTheWireVerbatim(string $pathAndQuery): void
    {
        self::$server->clearRequests();

        $raw = $this->transport->execute(new Request('GET', self::$server->baseUrl . $pathAndQuery), new RequestOptions());

        self::assertFalse($raw->isTransportError());
        self::assertSame(['GET ' . $pathAndQuery . ' HTTP/1.1'], self::$server->requests());
    }

    /** @return iterable<string, array{string}> */
    public static function dotSegmentPathProvider(): iterable
    {
        yield 'dot segments with a query' => ['/a/../b/./c?x=1'];
        yield 'percent-encoded dot segments' => ['/%2e%2e/x'];
    }

    public function testARawPathAsIsOptOutRestoresLibcurlDotSegmentRemoval(): void
    {
        self::$server->clearRequests();

        $raw = $this->transport->execute(
            new Request('GET', self::$server->baseUrl . '/a/../b/./c?x=1'),
            new RequestOptions([new RawCurlOption(CURLOPT_PATH_AS_IS, false)]),
        );

        self::assertFalse($raw->isTransportError());
        self::assertSame(['GET /b/c?x=1 HTTP/1.1'], self::$server->requests());
    }

    // CURLOPT_PATH_AS_IS covers every hop, yet libcurl still resolves a relative Location by RFC 3986
    #[DataProvider('relativeRedirectProvider')]
    public function testARelativeRedirectIsResolvedWithoutDotSegments(string $sourcePath, string $location): void
    {
        self::$server->clearRequests();
        $source = $sourcePath . '?url=' . rawurlencode($location);

        $raw = $this->transport->execute(new Request('GET', self::$server->baseUrl . $source), new RequestOptions());

        self::assertFalse($raw->isTransportError());
        self::assertSame('target-ok', (string) $raw->body);
        self::assertSame(['GET ' . $source . ' HTTP/1.1', 'GET /hit HTTP/1.1'], self::$server->requests());
    }

    /** @return iterable<string, array{string, string}> */
    public static function relativeRedirectProvider(): iterable
    {
        yield 'parent segments' => ['/dir/sub/redirect-to', '../../hit'];
        yield 'parent segments from a dotted base' => ['/a/../dir/sub/redirect-to', '../../hit'];
        yield 'dot segments inside the reference' => ['/dir/sub/redirect-to', './x/../../../hit'];
        yield 'dot segments in an absolute-path reference' => ['/dir/sub/redirect-to', '/a/./b/../../hit'];
    }

    public function testADisabledEncodingDeliversTheBodyAndItsHeadersExactlyAsTheServerSentThem(): void
    {
        $response = (new Client($this->transport))->send(
            new Request('GET', self::$server->baseUrl . '/gzip'),
            new RequestOptions([Encoding::disabled()]),
        );

        $body = (string) $response->getBody();
        self::assertStringStartsWith("\x1f\x8b", $body);
        self::assertSame('gzip', $response->getHeaderLine('Content-Encoding'));
        self::assertSame((string) strlen($body), $response->getHeaderLine('Content-Length'));
    }

    // the oracle is libcurl itself: the flag must say "decoded" exactly when the body arrived decoded
    #[DataProvider('decodingOptionProvider')]
    public function testTheDecodingFlagAgreesWithWhatLibcurlDidOnTheWire(Encoding|RawCurlOption $option): void
    {
        $raw = $this->transport->execute(
            new Request('GET', self::$server->baseUrl . '/gzip'),
            new RequestOptions([$option]),
        );

        self::assertFalse($raw->isTransportError());
        self::assertSame(!str_starts_with((string) $raw->body, "\x1f\x8b"), $raw->contentDecodingEnabled);
    }

    /** @return iterable<string, array{Encoding|RawCurlOption}> */
    public static function decodingOptionProvider(): iterable
    {
        yield 'default encoding' => [new Encoding()];
        yield 'named encoding' => [new Encoding('gzip')];
        yield 'disabled encoding' => [Encoding::disabled()];
        yield 'raw null encoding' => [new RawCurlOption(CURLOPT_ENCODING, null)];
        foreach ([
            'true' => true, 'false' => false, 'null' => null, '0' => 0, '1' => 1, "'0'" => '0',
            "''" => '', "'no'" => 'no', '0.5' => 0.5, '[0]' => [0], "'2abc'" => '2abc',
        ] as $label => $value) {
            yield 'content decoding ' . $label => [new RawCurlOption(CURLOPT_HTTP_CONTENT_DECODING, $value)];
        }
    }

    public function testLargeGetBodyIsSentAsGetThroughTheReadCallback(): void
    {
        $payload = str_repeat('a', CurlOptionsMapper::REPLAYABLE_BODY_LIMIT + 1);
        $request = (new Request('GET', self::$server->baseUrl . '/echo-request'))
            ->withBody($this->stream($payload));

        $raw = $this->transport->execute($request, new RequestOptions([ExpectContinue::doNotWait()]));

        self::assertFalse($raw->isTransportError());
        $received = json_decode((string) $raw->body, true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('GET', $received['method']);
        self::assertSame(strlen($payload), strlen($received['body']));
        self::assertSame(hash('sha256', $payload), hash('sha256', $received['body']));
    }

    public function testNonSeekablePipeBodyIsStreamedWithChunkedTransferEncoding(): void
    {
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        if ($pair === false) {
            self::fail('Unable to create a socket pair.');
        }
        fwrite($pair[0], 'pipe-payload');
        stream_socket_shutdown($pair[0], STREAM_SHUT_WR);

        $request = (new Request('POST', self::$server->baseUrl . '/echo-request'))
            ->withBody(new Stream($pair[1]));
        $response = (new Client())->send($request, new RequestOptions([ExpectContinue::doNotWait()]));
        $received = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);

        fclose($pair[0]);
        self::assertSame('POST', $received['method']);
        self::assertSame('pipe-payload', $received['body']);
        self::assertSame('chunked', strtolower($received['transferEncoding']));
    }

    // Regression guard for SEC-216: a streamed POST whose body stops short of the announced size used
    // to block the calling thread forever, because the mapper announced a length libcurl was not
    // enforcing. The elapsed-time bound is part of the assertion, not decoration.
    public function testStreamedPostShorterThanItsAnnouncedSizeFailsFastInsteadOfHanging(): void
    {
        $request = (new Request('POST', self::$server->baseUrl . '/echo'))
            ->withBody($this->truncatedSizedBody());
        $startedAt = microtime(true);

        try {
            (new Client())->send($request, new RequestOptions([ExpectContinue::doNotWait(), Timeout::seconds(5)]));
            self::fail('Expected a body shorter than its announced size to fail the transfer.');
        } catch (NetworkException $exception) {
            self::assertSame(CURLE_READ_ERROR, $exception->getCurlErrno());
        }

        self::assertLessThan(3.0, microtime(true) - $startedAt);
    }

    private function truncatedSizedBody(): StreamInterface
    {
        $reads = 0;
        $body = $this->createStub(StreamInterface::class);
        $body->method('getSize')->willReturn(CurlOptionsMapper::REPLAYABLE_BODY_LIMIT + 8_192);
        $body->method('isSeekable')->willReturn(false);
        $body->method('eof')->willReturnCallback(static function () use (&$reads): bool {
            return $reads > 0;
        });
        $body->method('read')->willReturnCallback(static function (int $length) use (&$reads): string {
            $reads++;
            return str_repeat('x', min(4_096, $length));
        });

        return $body;
    }

    public function testReadFailureIsWrappedAsARequestException(): void
    {
        $failure = new RuntimeException('read failed');
        $body = $this->createStub(StreamInterface::class);
        $body->method('getSize')->willReturn(null);
        $body->method('isSeekable')->willReturn(false);
        $body->method('eof')->willReturn(false);
        $body->method('read')->willThrowException($failure);
        $request = (new Request('POST', self::$server->baseUrl . '/echo'))
            ->withBody($body);

        try {
            (new Client())->send($request, new RequestOptions([ExpectContinue::doNotWait()]));
            self::fail('Expected reading the request body to fail.');
        } catch (RequestException $exception) {
            self::assertSame($request, $exception->getRequest());
            self::assertSame($failure, $exception->getPrevious());
        }
    }

    #[DataProvider('postRedirectProvider')]
    public function testPostRedirectUsesTheExpectedMethod(int $status, string $expectedBody): void
    {
        $request = (new Request('POST', self::$server->baseUrl . '/redirect-' . $status))
            ->withBody($this->stream('redirect-payload'));

        $raw = $this->transport->execute($request, new RequestOptions());

        self::assertFalse($raw->isTransportError());
        self::assertSame($expectedBody, (string) $raw->body);
    }

    /** @return iterable<string, array{int, string}> */
    public static function postRedirectProvider(): iterable
    {
        yield '302 becomes GET' => [302, 'GET:'];
        yield '303 becomes GET' => [303, 'GET:'];
        yield '307 remains POST with its body' => [307, 'POST:redirect-payload'];
    }

    public function testConnectionRefusedIsATransportError(): void
    {
        $request = new Request('GET', 'http://127.0.0.1:1/');
        $raw = $this->transport->execute($request, new RequestOptions([ConnectTimeout::seconds(1)]));

        self::assertTrue($raw->isTransportError());
        self::assertNotSame(0, $raw->errno);
    }

    public function testTimeoutIsATransportError(): void
    {
        $request = new Request('GET', self::$server->baseUrl . '/slow');
        $raw = $this->transport->execute($request, new RequestOptions([Timeout::seconds(1)]));

        self::assertTrue($raw->isTransportError());
    }

    public function testTransferInfoCollectorIsPopulatedWhenAttached(): void
    {
        $collector = new TransferInfoCollector();
        $request = new Request('GET', self::$server->baseUrl . '/ok');

        $this->transport->execute($request, new RequestOptions([], $collector));

        self::assertGreaterThanOrEqual(0.0, $collector->get()->totalTimeMs);
    }

    #[DataProvider('refusedBufferWriteResults')]
    public function testARefusedLocalResponseBufferReachesTheConsumerAsAClientException(int|false $result, string $expectedCount): void
    {
        $client = new Client($this->transport);
        $interceptor = $this->interceptTransportFunctions()->refuseNextWrite($result);
        $caught = null;

        try {
            $interceptor->whileArmed(static fn () => $client->send(new Request('GET', self::$server->baseUrl . '/ok')));
        } catch (\Throwable $exception) {
            $caught = $exception;
        }

        self::assertInstanceOf(ResponseBufferException::class, $caught);
        self::assertInstanceOf(ClientExceptionInterface::class, $caught);
        self::assertStringContainsString('buffer the response body locally', $caught->getMessage());
        self::assertStringContainsString($expectedCount, $caught->getMessage());
    }

    /** @return iterable<string, array{int|false, string}> */
    public static function refusedBufferWriteResults(): iterable
    {
        yield 'refused outright' => [false, 'none of'];
        yield 'no bytes accepted' => [0, '0 of'];
        yield 'short write' => [1, '1 of'];
    }

    private function stream(string $contents): Stream
    {
        $resource = fopen('php://temp', 'r+');
        fwrite($resource, $contents);
        rewind($resource);
        return new Stream($resource);
    }
}

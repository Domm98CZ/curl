<?php declare(strict_types=1);

namespace Domm98CZ\Curl\Tests\Transport;

use Domm98CZ\Curl\Client;
use Domm98CZ\Curl\Contract\StreamHandlerInterface;
use Domm98CZ\Curl\Contract\TransferInfoCollectorInterface;
use Domm98CZ\Curl\Exceptions\NetworkException;
use Domm98CZ\Curl\Options\ConnectTimeout;
use Domm98CZ\Curl\Options\Timeout;
use Domm98CZ\Curl\Psr7\Request;
use Domm98CZ\Curl\Psr7\Stream;
use Domm98CZ\Curl\RequestBuilder;
use Domm98CZ\Curl\RequestOptions;
use Domm98CZ\Curl\Streaming\HttpStreamHandler;
use Domm98CZ\Curl\Tests\Fixtures\ExpectContinue;
use Domm98CZ\Curl\Tests\Fixtures\TestServer;
use Domm98CZ\Curl\TransferInfoCollector;
use Domm98CZ\Curl\Transport\CurlOptionsMapper;
use Domm98CZ\Curl\Transport\CurlTransport;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;

final class CurlTransportStreamingTest extends TestCase
{
    private const STREAM_CHUNK_INTERVAL_MS = 120;

    private static TestServer $server;

    public static function setUpBeforeClass(): void
    {
        self::$server = new TestServer(8101);
        self::$server->start();
    }

    public static function tearDownAfterClass(): void
    {
        self::$server->stop();
    }

    public function testChunksArriveDuringTheTransferNotAfterIt(): void
    {
        $arrivals = [];
        $handler = new HttpStreamHandler(
            onChunk: function (string $chunk) use (&$arrivals): void {
                $arrivals[] = ['at' => hrtime(true), 'chunk' => $chunk];
            },
        );

        $start = hrtime(true);
        (new CurlTransport())->execute(
            new Request('GET', self::$server->baseUrl . '/stream'),
            new RequestOptions([], null, $handler)
        );
        $finished = hrtime(true);

        self::assertGreaterThan(1, count($arrivals));
        self::assertLessThan($finished, $arrivals[0]['at']);
        self::assertGreaterThan($start, $arrivals[0]['at']);

        $elapsedAfterFirstChunkMs = ($finished - $arrivals[0]['at']) / 1_000_000;
        self::assertGreaterThan(self::STREAM_CHUNK_INTERVAL_MS * 2, $elapsedAfterFirstChunkMs);

        $body = implode('', array_column($arrivals, 'chunk'));
        self::assertStringContainsString('data: chunk-1', $body);
        self::assertStringContainsString('data: chunk-4', $body);
    }

    public function testStatusAndHeadersReachTheConsumerBeforeTheBody(): void
    {
        $events = [];
        $handler = new HttpStreamHandler(
            onChunk: static function (string $chunk) use (&$events): void {
                $events[] = 'chunk';
            },
            onResponse: static function (ResponseInterface $response) use (&$events): void {
                $events[] = 'headers:' . $response->getStatusCode() . ':' . $response->getHeaderLine('Content-Type');
            },
        );

        (new CurlTransport())->execute(
            new Request('GET', self::$server->baseUrl . '/stream'),
            new RequestOptions([], null, $handler)
        );

        self::assertNotSame([], $events);
        self::assertStringStartsWith('headers:200:text/event-stream', $events[0]);
        self::assertContains('chunk', $events);
    }

    public function testAStreamedResponseCarriesHeadersButNoBufferedBody(): void
    {
        $received = '';
        $handler = new HttpStreamHandler(
            onChunk: static function (string $chunk) use (&$received): void {
                $received .= $chunk;
            },
        );

        $response = RequestBuilder::get(self::$server->baseUrl . '/stream')
            ->withStreamHandler($handler)
            ->send();

        self::assertSame(200, $response->getStatusCode());
        self::assertStringStartsWith('text/event-stream', $response->getHeaderLine('Content-Type'));
        self::assertSame('', (string) $response->getBody());
        self::assertStringContainsString('data: chunk-4', $received);
    }

    public function testWithoutAHandlerTheBodyIsStillBufferedAsBefore(): void
    {
        $raw = (new CurlTransport())->execute(
            new Request('GET', self::$server->baseUrl . '/ok'),
            new RequestOptions()
        );

        self::assertTrue($raw->bodyBuffered);
        self::assertSame('ok', (string) $raw->body);
    }

    public function testTheLibraryDoesNotParseServerSentEvents(): void
    {
        $chunks = [];
        $handler = new HttpStreamHandler(
            onChunk: static function (string $chunk) use (&$chunks): void {
                $chunks[] = $chunk;
            },
        );

        (new CurlTransport())->execute(
            new Request('GET', self::$server->baseUrl . '/stream'),
            new RequestOptions([], null, $handler)
        );

        // the raw SSE framing reaches the consumer untouched - no event objects, no data: stripping
        self::assertStringContainsString("data: chunk-1\n\n", implode('', $chunks));
    }

    public function testACustomStreamHandlerSeesEveryRawHeaderLine(): void
    {
        $handler = new class implements StreamHandlerInterface {
            /** @var string[] */
            public array $headerLines = [];

            public string $body = '';

            public function onHeaderLine(string $line): void
            {
                $this->headerLines[] = $line;
            }

            public function onChunk(string $chunk): void
            {
                $this->body .= $chunk;
            }
        };

        (new CurlTransport())->execute(
            new Request('GET', self::$server->baseUrl . '/ok'),
            new RequestOptions([], null, $handler)
        );

        self::assertStringStartsWith('HTTP/1.1 200', $handler->headerLines[0]);
        self::assertSame('ok', $handler->body);
    }

    public function testTransferInfoCollectorIsCalledOnceWhenOnChunkThrows(): void
    {
        $failure = new RuntimeException('chunk callback failed');
        $collector = $this->createMock(TransferInfoCollectorInterface::class);
        $collector->expects(self::once())->method('collect')->with(self::isType('array'));
        $handler = new HttpStreamHandler(
            onChunk: static function () use ($failure): void {
                throw $failure;
            },
        );
        $request = new Request('GET', self::$server->baseUrl . '/ok');

        try {
            (new CurlTransport())->execute($request, new RequestOptions([], $collector, $handler));
            self::fail('Expected the chunk callback to fail.');
        } catch (RuntimeException $exception) {
            self::assertSame($failure, $exception);
        }
    }

    public function testTransferInfoCollectorIsCalledOnceForASuccessfulTransfer(): void
    {
        $collector = $this->createMock(TransferInfoCollectorInterface::class);
        $collector->expects(self::once())->method('collect')->with(self::isType('array'));

        (new CurlTransport())->execute(
            new Request('GET', self::$server->baseUrl . '/ok'),
            new RequestOptions([], $collector)
        );
    }

    public function testTransferInfoCarriesTimeToFirstByte(): void
    {
        $collector = new TransferInfoCollector();

        (new Client())->send(
            new Request('GET', self::$server->baseUrl . '/ok'),
            new RequestOptions([], $collector)
        );

        $info = $collector->get();
        self::assertGreaterThan(0.0, $info->startTransferTimeMs);
        self::assertGreaterThanOrEqual($info->connectTimeMs, $info->startTransferTimeMs);
        self::assertLessThanOrEqual($info->totalTimeMs, $info->startTransferTimeMs);
    }

    public function testATimeoutIsDistinguishableFromAConnectFailureByErrno(): void
    {
        $client = new Client();

        try {
            $client->send(
                new Request('GET', self::$server->baseUrl . '/slow'),
                new RequestOptions([new Timeout(1)])
            );
            self::fail('Expected the slow endpoint to time out.');
        } catch (NetworkException $timeout) {
            self::assertSame(CURLE_OPERATION_TIMEDOUT, $timeout->getCurlErrno());
        }

        try {
            $client->send(
                new Request('GET', 'http://127.0.0.1:1/'),
                new RequestOptions([new ConnectTimeout(2)])
            );
            self::fail('Expected the closed port to refuse the connection.');
        } catch (NetworkException $refused) {
            self::assertSame(CURLE_COULDNT_CONNECT, $refused->getCurlErrno());
            self::assertNotSame($timeout->getCurlErrno(), $refused->getCurlErrno());
        }
    }

    public function testAStreamedPostBodyLargerThanTheReplayLimitReturnsThe307Redirect(): void
    {
        $resource = fopen('php://temp', 'r+');
        fwrite($resource, str_repeat('a', CurlOptionsMapper::REPLAYABLE_BODY_LIMIT + 1));
        rewind($resource);

        $request = (new Request('POST', self::$server->baseUrl . '/redirect-307'))->withBody(new Stream($resource));

        $response = (new Client())->send($request, new RequestOptions([ExpectContinue::doNotWait()]));

        self::assertSame(307, $response->getStatusCode());
        self::assertSame('/echo-method', $response->getHeaderLine('Location'));
    }

    public function testAStreamedPostBodyReturnsThe303RedirectInsteadOfFollowingIt(): void
    {
        $payload = str_repeat('a', CurlOptionsMapper::REPLAYABLE_BODY_LIMIT + 1);
        $request = (new Request('POST', self::$server->baseUrl . '/redirect-303'))
            ->withBody($this->stream($payload));

        $response = (new Client())->send($request, new RequestOptions([ExpectContinue::doNotWait()]));

        self::assertSame(303, $response->getStatusCode());
        self::assertSame('/echo-method', $response->getHeaderLine('Location'));
    }

    public function testAReplayablePostBodyAtTheLimitSurvivesA307Redirect(): void
    {
        $payload = str_repeat('a', CurlOptionsMapper::REPLAYABLE_BODY_LIMIT);
        $request = (new Request('POST', self::$server->baseUrl . '/redirect-307'))->withBody($this->stream($payload));

        $response = (new Client())->sendRequest($request);
        $received = (string) $response->getBody();

        self::assertStringStartsWith('POST:', $received);
        self::assertSame(strlen($payload) + 5, strlen($received));
        self::assertSame(hash('sha256', $payload), hash('sha256', substr($received, 5)));
    }

    public function testAStreamHandlerReceivesBothResponsesOfAFollowedRedirect(): void
    {
        $statuses = [];
        $handler = new HttpStreamHandler(
            onChunk: static function (string $_): void {
            },
            onResponse: static function (ResponseInterface $response) use (&$statuses): void {
                $statuses[] = $response->getStatusCode();
            },
        );

        $response = (new Client())->send(
            new Request('GET', self::$server->baseUrl . '/redirect'),
            new RequestOptions([], null, $handler)
        );

        self::assertCount(2, $statuses);
        self::assertSame([301, 200], $statuses);
        self::assertSame(200, $response->getStatusCode());
        self::assertSame(200, $statuses[array_key_last($statuses)]);
    }

    public function testAHeadResponseCallsOnResponseButNeverOnChunk(): void
    {
        $chunks = [];
        $statuses = [];
        $handler = new HttpStreamHandler(
            onChunk: static function (string $chunk) use (&$chunks): void {
                $chunks[] = $chunk;
            },
            onResponse: static function (ResponseInterface $response) use (&$statuses): void {
                $statuses[] = $response->getStatusCode();
            },
        );

        $response = (new Client())->send(
            new Request('HEAD', self::$server->baseUrl . '/large-plain'),
            new RequestOptions([], null, $handler)
        );

        self::assertSame([], $chunks);
        self::assertSame([200], $statuses);
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('2097152', $response->getHeaderLine('Content-Length'));
    }

    public function testCompressedResponsesAreDecodedByDefaultAndHeadersMatchTheBody(): void
    {
        $response = (new Client())->sendRequest(new Request('GET', self::$server->baseUrl . '/gzip'));

        $body = (string) $response->getBody();
        self::assertStringStartsWith('compressible-payload', $body);
        self::assertFalse($response->hasHeader('Content-Encoding'));
        self::assertSame((string) strlen($body), $response->getHeaderLine('Content-Length'));
    }

    private function stream(string $contents): Stream
    {
        $resource = fopen('php://temp', 'r+');
        fwrite($resource, $contents);
        rewind($resource);

        return new Stream($resource);
    }
}

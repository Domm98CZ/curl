<?php declare(strict_types=1);

namespace Domm98CZ\Curl\Tests\Transport;

use Domm98CZ\Curl\Client;
use Domm98CZ\Curl\Options\MaxResponseSize;
use Domm98CZ\Curl\Psr7\Request;
use Domm98CZ\Curl\Psr7\Stream;
use Domm98CZ\Curl\RawResponse;
use Domm98CZ\Curl\RequestOptions;
use Domm98CZ\Curl\Tests\Fixtures\ContinueTestServer;
use Domm98CZ\Curl\Transport\CurlTransport;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class InterimResponseSizeTest extends TestCase
{
    private static ContinueTestServer $server;

    public static function setUpBeforeClass(): void
    {
        self::$server = new ContinueTestServer(8117);
        self::$server->start();
    }

    public static function tearDownAfterClass(): void
    {
        self::$server->stop();
    }

    public function testTheDiscardedInterimBlockStillReachesTheHeaderBuffer(): void
    {
        $raw = $this->execute('/with-interim');

        self::assertFalse($raw->isTransportError());
        self::assertStringStartsWith(ContinueTestServer::INTERIM_BLOCK, $raw->headerRaw);
        self::assertSame('final', (string) $raw->body);
        self::assertSame(
            strlen(ContinueTestServer::INTERIM_BLOCK),
            strlen($raw->headerRaw) - strlen($this->execute('/without-interim')->headerRaw)
        );
    }

    #[DataProvider('interimCountProvider')]
    public function testInterimResponseBytesAreChargedToTheSameCeilingAsTheFinalResponse(int $interimCount): void
    {
        $withoutInterim = $this->execute('/without-interim');
        $finalBytes = strlen($withoutInterim->headerRaw) + strlen((string) $withoutInterim->body);
        $interimBytes = $interimCount * strlen(ContinueTestServer::INTERIM_BLOCK);
        $path = '/with-interim?interim=' . $interimCount;

        $exactlyTheFinalResponse = $this->execute('/without-interim', $finalBytes);
        self::assertFalse($exactlyTheFinalResponse->isTransportError());
        self::assertSame('final', (string) $exactlyTheFinalResponse->body);

        $refused = $this->execute($path, $finalBytes);
        self::assertSame(CURLE_WRITE_ERROR, $refused->errno);
        self::assertSame(sprintf('Maximum response size of %d bytes exceeded.', $finalBytes), $refused->error);
        self::assertTrue($refused->responseSizeExceeded);

        $shortByOneByte = $this->execute($path, $finalBytes + $interimBytes - 1);
        self::assertSame(CURLE_WRITE_ERROR, $shortByOneByte->errno);

        $raisedByTheInterimBytes = $this->execute($path, $finalBytes + $interimBytes);
        self::assertFalse($raisedByTheInterimBytes->isTransportError());
        self::assertSame('final', (string) $raisedByTheInterimBytes->body);
    }

    /** @return iterable<string, array{int}> */
    public static function interimCountProvider(): iterable
    {
        yield 'one interim block' => [1];
        yield 'three interim blocks' => [3];
    }

    public function testAnExpectHandshakeLibcurlStartedItselfIsAnsweredAndCharged(): void
    {
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        if ($pair === false) {
            self::fail('Unable to create a socket pair.');
        }
        fwrite($pair[0], str_repeat('a', 8_192));
        stream_socket_shutdown($pair[0], STREAM_SHUT_WR);
        $request = (new Request('POST', self::$server->baseUrl . '/with-interim'))->withBody(new Stream($pair[1]));
        $startedAt = microtime(true);

        $response = (new Client())->send($request);
        $elapsed = microtime(true) - $startedAt;
        fclose($pair[0]);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('final', (string) $response->getBody());
        // libcurl waits out CURLOPT_EXPECT_100_TIMEOUT_MS (1000 ms by default) when nothing answers,
        // so finishing well inside it is what shows the interim response came off the wire.
        self::assertLessThan(0.5, $elapsed);
    }

    private function execute(string $path, ?int $maximumResponseSize = null): RawResponse
    {
        $options = $maximumResponseSize === null
            ? new RequestOptions()
            : new RequestOptions([new MaxResponseSize($maximumResponseSize)]);

        return (new CurlTransport())->execute(new Request('GET', self::$server->baseUrl . $path), $options);
    }
}

<?php declare(strict_types=1);

namespace Domm98CZ\Curl\Tests;

use Domm98CZ\Curl\BodyReplay;
use Domm98CZ\Curl\Client;
use Domm98CZ\Curl\Exceptions\NetworkException;
use Domm98CZ\Curl\Exceptions\RequestException;
use Domm98CZ\Curl\Options\RawCurlOption;
use Domm98CZ\Curl\Options\Timeout;
use Domm98CZ\Curl\Psr7\Request;
use Domm98CZ\Curl\Psr7\Stream;
use Domm98CZ\Curl\RawResponse;
use Domm98CZ\Curl\RequestOptions;
use Domm98CZ\Curl\Streaming\HttpStreamHandler;
use Domm98CZ\Curl\Tests\Fakes\FakeTransport;
use Domm98CZ\Curl\TransferInfoCollector;
use Domm98CZ\Curl\Transport\CurlOptionsMapper;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ClientTest extends TestCase
{
    private function okRawResponse(string $body = 'ok'): RawResponse
    {
        $resource = fopen('php://temp', 'r+');
        fwrite($resource, $body);
        rewind($resource);
        return new RawResponse(0, '', "HTTP/1.1 200 OK\r\n\r\n", new Stream($resource), []);
    }

    public function testSendRequestReturnsParsedResponseOnSuccess(): void
    {
        $client = new Client(new FakeTransport($this->okRawResponse('hello')));
        $response = $client->sendRequest(new Request('GET', 'https://example.com'));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('hello', (string) $response->getBody());
    }

    public function testHttp4xxAnd5xxDoNotThrow(): void
    {
        $raw = new RawResponse(0, '', "HTTP/1.1 500 Internal Server Error\r\n\r\n", new Stream(fopen('php://temp', 'r+')), []);
        $client = new Client(new FakeTransport($raw));

        $response = $client->sendRequest(new Request('GET', 'https://example.com'));
        self::assertSame(500, $response->getStatusCode());
    }

    public function testConnectionFailureThrowsNetworkException(): void
    {
        $raw = new RawResponse(CURLE_COULDNT_CONNECT, 'Connection refused', '', new Stream(fopen('php://temp', 'r+')), []);
        $client = new Client(new FakeTransport($raw));

        $this->expectException(NetworkException::class);
        $client->sendRequest(new Request('GET', 'https://example.com'));
    }

    public function testMalformedUrlThrowsRequestException(): void
    {
        $raw = new RawResponse(CURLE_URL_MALFORMAT, 'Malformed URL', '', new Stream(fopen('php://temp', 'r+')), []);
        $client = new Client(new FakeTransport($raw));

        $this->expectException(RequestException::class);
        $client->sendRequest(new Request('GET', 'not a url'));
    }

    public function testSendPassesOptionsToTransport(): void
    {
        $transport = new FakeTransport($this->okRawResponse());
        $client = new Client($transport);
        $options = new RequestOptions();

        $client->send(new Request('GET', 'https://example.com'), $options);

        self::assertSame($options, $transport->calls[0]['options']);
    }

    public function testSendRequestUsesDefaultOptionsWithNoTransferInfoCollector(): void
    {
        $transport = new FakeTransport($this->okRawResponse());
        $client = new Client($transport);

        $client->sendRequest(new Request('GET', 'https://example.com'));

        self::assertNull($transport->calls[0]['options']->getTransferInfoCollector());
    }

    public function testSendForwardsATransferInfoCollector(): void
    {
        $collector = new TransferInfoCollector();
        $raw = $this->okRawResponse();
        $transport = new FakeTransport($raw);
        $client = new Client($transport);

        $client->send(new Request('GET', 'https://example.com'), new RequestOptions([], $collector));

        self::assertSame($collector, $transport->calls[0]['options']->getTransferInfoCollector());
    }

    public function testDefaultOptionsReachTheTransportWhenSendRequestCarriesNone(): void
    {
        $transport = new FakeTransport($this->okRawResponse());
        $defaults = new RequestOptions([Timeout::seconds(5)]);
        $client = new Client($transport, defaultOptions: $defaults);

        $client->sendRequest(new Request('GET', 'https://example.com'));

        $sent = $transport->calls[0]['options'];
        self::assertSame($defaults->all(), $sent->all());
        self::assertNull($sent->getTransferInfoCollector());
        self::assertNull($sent->getStreamHandler());
    }

    public function testPerRequestOptionsFollowTheDefaultsAndWinOnTheSameKey(): void
    {
        $transport = new FakeTransport($this->okRawResponse());
        $client = new Client($transport, defaultOptions: new RequestOptions([Timeout::seconds(5)]));
        $collector = new TransferInfoCollector();
        $request = new Request('GET', 'https://example.com');

        $client->send($request, new RequestOptions([Timeout::seconds(9)], $collector));

        $sent = $transport->calls[0]['options'];
        self::assertCount(2, $sent->all());
        self::assertSame(9000, (new CurlOptionsMapper())->map($request, $sent)[CURLOPT_TIMEOUT_MS]);
        self::assertSame($collector, $sent->getTransferInfoCollector());
    }

    public function testReplayabilityIsJudgedWithTheDefaultOptionsApplied(): void
    {
        $transport = new FakeTransport();
        $request = new Request('GET', 'https://example.com');
        $withDefaults = new Client($transport, defaultOptions: new RequestOptions([new RawCurlOption(CURLOPT_UPLOAD, true)]));

        self::assertSame(BodyReplay::Replayable, (new Client($transport))->replayabilityOf($request));
        self::assertSame(BodyReplay::NotReplayable, $withDefaults->replayabilityOf($request));
    }

    #[DataProvider('unshareableDefaultsProvider')]
    public function testDefaultOptionsCarryingPerTransferStateAreRefused(RequestOptions $defaults): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('cannot carry a stream handler or a transfer info collector');

        new Client(new FakeTransport(), defaultOptions: $defaults);
    }

    /** @return iterable<string, array{RequestOptions}> */
    public static function unshareableDefaultsProvider(): iterable
    {
        yield 'transfer info collector' => [new RequestOptions([], new TransferInfoCollector())];
        yield 'stream handler' => [new RequestOptions([], null, new HttpStreamHandler(static fn (string $chunk) => null))];
    }
}

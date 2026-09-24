<?php declare(strict_types=1);

namespace Domm98CZ\Curl\Tests;

use Domm98CZ\Curl\AsyncClient;
use Domm98CZ\Curl\Exceptions\NetworkException;
use Domm98CZ\Curl\Exceptions\RequestException;
use Domm98CZ\Curl\Psr7\Request;
use Domm98CZ\Curl\Psr7\Stream;
use Domm98CZ\Curl\RawResponse;
use Domm98CZ\Curl\Tests\Fakes\FakeMultiTransport;
use Http\Promise\Promise;
use PHPUnit\Framework\TestCase;

final class AsyncClientTest extends TestCase
{
    private function rawResponse(int $statusCode, string $body): RawResponse
    {
        $resource = fopen('php://temp', 'r+');
        fwrite($resource, $body);
        rewind($resource);
        return new RawResponse(0, '', sprintf("HTTP/1.1 %d X\r\n\r\n", $statusCode), new Stream($resource), []);
    }

    public function testSendAsyncReturnsAPendingPromise(): void
    {
        $client = new AsyncClient(new FakeMultiTransport($this->rawResponse(200, 'a')));
        $promise = $client->sendAsync(new Request('GET', '/'));

        self::assertSame(Promise::PENDING, $promise->getState());
    }

    public function testWaitResolvesToTheParsedResponse(): void
    {
        $client = new AsyncClient(new FakeMultiTransport($this->rawResponse(200, 'a')));
        $response = $client->sendAsync(new Request('GET', '/'))->wait();

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('a', (string) $response->getBody());
    }

    public function testTransportErrorRejectsThePromise(): void
    {
        $failure = new RawResponse(CURLE_COULDNT_CONNECT, 'refused', '', new Stream(fopen('php://temp', 'r+')), []);
        $client = new AsyncClient(new FakeMultiTransport($failure));

        $this->expectException(NetworkException::class);
        $client->sendAsync(new Request('GET', '/'))->wait();
    }

    public function testMalformedUrlRejectsWithRequestException(): void
    {
        $failure = new RawResponse(CURLE_URL_MALFORMAT, 'Malformed URL', '', new Stream(fopen('php://temp', 'r+')), []);
        $client = new AsyncClient(new FakeMultiTransport($failure));

        $this->expectException(RequestException::class);
        $client->sendAsync(new Request('GET', 'not a url'))->wait();
    }

    public function testNonHttpSchemesAreRejectedBeforeTheTransportIsOpened(): void
    {
        foreach (['file:///etc/hostname', 'gopher://example.com/x', 'dict://example.com/x', 'ftp://example.com/x'] as $uri) {
            $transport = new FakeMultiTransport();
            $client = new AsyncClient($transport);

            try {
                $client->sendAsync(new Request('GET', $uri));
                self::fail(sprintf('Expected URI "%s" to be refused.', $uri));
            } catch (RequestException $exception) {
                self::assertStringContainsString(parse_url($uri, PHP_URL_SCHEME), $exception->getMessage());
                self::assertSame(0, $transport->addCalls);
            }
        }
    }

    public function testFileUploadIsRejectedWithoutWritingAnyBytes(): void
    {
        $target = sys_get_temp_dir() . '/curl-async-protocol-probe-' . bin2hex(random_bytes(4)) . '.txt';
        $resource = fopen('php://temp', 'r+');
        fwrite($resource, str_repeat('x', (1024 * 1024) + 1));
        rewind($resource);

        $request = (new Request('PUT', 'file://' . $target))->withBody(new Stream($resource));

        try {
            (new AsyncClient())->sendAsync($request)->wait();
            self::fail('Expected the file scheme to be refused.');
        } catch (RequestException) {
            self::assertFileDoesNotExist($target);
        } finally {
            if (is_file($target)) {
                unlink($target);
            }
        }
    }

    public function testMultipleInFlightRequestsAreAllRegisteredBeforeAnyWait(): void
    {
        $transport = new FakeMultiTransport($this->rawResponse(200, 'a'), $this->rawResponse(200, 'b'));
        $client = new AsyncClient($transport);

        $promiseA = $client->sendAsync(new Request('GET', '/a'));
        $promiseB = $client->sendAsync(new Request('GET', '/b'));

        self::assertSame('a', (string) $promiseA->wait()->getBody());
        self::assertSame('b', (string) $promiseB->wait()->getBody());
    }

    public function testThenInvokesTheFulfilledCallback(): void
    {
        $client = new AsyncClient(new FakeMultiTransport($this->rawResponse(200, 'a')));
        $seen = null;

        $client->sendAsync(new Request('GET', '/'))->then(function ($response) use (&$seen): void {
            $seen = $response->getStatusCode();
        });

        self::assertSame(200, $seen);
    }
}

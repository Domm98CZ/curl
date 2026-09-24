<?php declare(strict_types=1);

namespace Domm98CZ\Curl\Tests;

use Domm98CZ\Curl\Client;
use Domm98CZ\Curl\Exceptions\NetworkException;
use Domm98CZ\Curl\Middleware\RetryClient;
use Domm98CZ\Curl\Options\HttpVersion;
use Domm98CZ\Curl\Psr7\Stream;
use Domm98CZ\Curl\RawResponse;
use Domm98CZ\Curl\RequestBuilder;
use Domm98CZ\Curl\Retry\ExponentialBackoff;
use Domm98CZ\Curl\Retry\MaxAttemptsRetryPolicy;
use Domm98CZ\Curl\Streaming\HttpStreamHandler;
use Domm98CZ\Curl\Tests\Fakes\FakeMultiTransport;
use Domm98CZ\Curl\Tests\Fakes\FakeSleeper;
use Domm98CZ\Curl\Tests\Fakes\FakeTransport;
use Domm98CZ\Curl\TransferInfoCollector;
use Domm98CZ\Curl\AsyncClient;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RequestBuilderTest extends TestCase
{
    private function okRaw(string $body = 'ok'): RawResponse
    {
        $resource = fopen('php://temp', 'r+');
        fwrite($resource, $body);
        rewind($resource);
        return new RawResponse(0, '', "HTTP/1.1 200 OK\r\n\r\n", new Stream($resource), []);
    }

    public function testGetBuildsAGetRequestAndSends(): void
    {
        $transport = new FakeTransport($this->okRaw('hello'));
        $response = RequestBuilder::get('https://example.com')->send(new Client($transport));

        self::assertSame('GET', $transport->calls[0]['request']->getMethod());
        self::assertSame('https://example.com', (string) $transport->calls[0]['request']->getUri());
        self::assertSame('hello', (string) $response->getBody());
    }

    public function testWithHeaderIsImmutableAndReachesTheRequest(): void
    {
        $originalTransport = new FakeTransport($this->okRaw());
        $withHeaderTransport = new FakeTransport($this->okRaw());

        $builder = RequestBuilder::get('/');
        $withHeader = $builder->withHeader('X-Test', 'a');

        $builder->send(new Client($originalTransport));
        $withHeader->send(new Client($withHeaderTransport));

        self::assertSame([], $originalTransport->calls[0]['request']->getHeader('X-Test'));
        self::assertSame(['a'], $withHeaderTransport->calls[0]['request']->getHeader('X-Test'));
    }

    #[DataProvider('headerCasePairs')]
    public function testHeadersAreMergedCaseInsensitivelyAndKeepTheFirstSpelling(
        string $firstName,
        string $secondName,
    ): void
    {
        $transport = new FakeTransport($this->okRaw());

        RequestBuilder::get('/')
            ->withHeader($firstName, 'a')
            ->withAddedHeader($secondName, 'b')
            ->send(new Client($transport));

        self::assertSame([$firstName => ['a', 'b']], $transport->calls[0]['request']->getHeaders());
    }

    public static function headerCasePairs(): iterable
    {
        yield 'uppercase then lowercase' => ['X-Foo', 'x-foo'];
        yield 'lowercase then uppercase' => ['x-foo', 'X-Foo'];
    }

    public function testWithQueryParamsAppendsToTheUri(): void
    {
        $transport = new FakeTransport($this->okRaw());
        RequestBuilder::get('https://example.com/search?existing=1')
            ->withQueryParams(['q' => 'a b', 'page' => '2'])
            ->send(new Client($transport));

        $uri = (string) $transport->calls[0]['request']->getUri();
        self::assertSame('https://example.com/search?existing=1&q=a%20b&page=2', $uri);
    }

    public function testGetUriShowsTheMergedQueryWithoutSending(): void
    {
        $builder = RequestBuilder::get('https://example.com/search?q=old&page=1')->withQueryParams(['page' => '2']);

        self::assertSame('https://example.com/search?q=old&page=2', (string) $builder->getUri());
    }

    public function testGetRequestShowsWhatSendWouldPutOnTheWire(): void
    {
        $request = RequestBuilder::post('https://example.com/users')
            ->withHeader('X-Test', 'a')
            ->withJson(['name' => 'Ada'])
            ->getRequest();

        self::assertSame('POST', $request->getMethod());
        self::assertSame('https://example.com/users', (string) $request->getUri());
        self::assertSame('a', $request->getHeaderLine('X-Test'));
        self::assertSame('application/json', $request->getHeaderLine('Content-Type'));
        self::assertSame('{"name":"Ada"}', (string) $request->getBody());
    }

    public function testWithQueryParamsReplacesAnExistingPairWithTheSameKey(): void
    {
        $transport = new FakeTransport($this->okRaw());
        RequestBuilder::get('https://example.com/search?page=1&sort=asc')
            ->withQueryParams(['page' => '2'])
            ->send(new Client($transport));

        $uri = (string) $transport->calls[0]['request']->getUri();
        self::assertSame('https://example.com/search?sort=asc&page=2', $uri);
    }

    // parse_str() would turn filter.name into filter_name and ids[] into an array; existing pairs
    // must survive byte for byte and keys must be compared as they are.
    #[DataProvider('queryKeysParseStrWouldMangle')]
    public function testWithQueryParamsKeepsKeysParseStrWouldRewrite(string $existing, string $expected): void
    {
        $transport = new FakeTransport($this->okRaw());
        RequestBuilder::get('https://example.com/search?' . $existing)
            ->withQueryParams(['page' => '2'])
            ->send(new Client($transport));

        $uri = (string) $transport->calls[0]['request']->getUri();
        self::assertSame('https://example.com/search?' . $expected, $uri);
    }

    /** @return iterable<string, array{string, string}> */
    public static function queryKeysParseStrWouldMangle(): iterable
    {
        yield 'dot in key' => ['filter.name=x', 'filter.name=x&page=2'];
        yield 'space in key' => ['a%20b=1', 'a%20b=1&page=2'];
        yield 'bracket keys stay separate pairs' => ['ids%5B%5D=1&ids%5B%5D=2', 'ids%5B%5D=1&ids%5B%5D=2&page=2'];
        yield 'flag without a value' => ['flag', 'flag&page=2'];
        yield 'encoded key replaced by its decoded spelling' => ['pa%67e=1', 'page=2'];
    }

    public function testWithJsonSetsBodyAndContentType(): void
    {
        $transport = new FakeTransport($this->okRaw());
        RequestBuilder::post('/')->withJson(['a' => 1])->send(new Client($transport));

        $request = $transport->calls[0]['request'];
        self::assertSame('{"a":1}', (string) $request->getBody());
        self::assertSame('application/json', $request->getHeaderLine('Content-Type'));
    }

    public function testWithAcceptSetsTheAcceptHeader(): void
    {
        $request = RequestBuilder::get('/')->withAccept('application/json')->getRequest();

        self::assertSame('application/json', $request->getHeaderLine('Accept'));
    }

    public function testWithAcceptReplacesAnEarlierAcceptHeader(): void
    {
        $request = RequestBuilder::get('/')->withHeader('accept', 'text/html')->withAccept('application/json')->getRequest();

        self::assertSame(['application/json'], $request->getHeader('Accept'));
    }

    public function testWithXmlSetsBodyAndContentType(): void
    {
        $xml = '<?xml version="1.0"?><user><name>Ada</name></user>';
        $transport = new FakeTransport($this->okRaw());
        RequestBuilder::post('/')->withXml($xml)->send(new Client($transport));

        $request = $transport->calls[0]['request'];
        self::assertSame($xml, (string) $request->getBody());
        self::assertSame('application/xml', $request->getHeaderLine('Content-Type'));
    }

    public function testWithXmlReplacesAnEarlierContentType(): void
    {
        $request = RequestBuilder::post('/')
            ->withHeader('Content-Type', 'text/plain')
            ->withXml('<a/>')
            ->getRequest();

        self::assertSame(['application/xml'], $request->getHeader('Content-Type'));
    }

    public function testWithJsonReplacesAnEarlierContentType(): void
    {
        $request = RequestBuilder::post('/')
            ->withHeader('Content-Type', 'text/plain')
            ->withJson(['a' => 1])
            ->getRequest();

        self::assertSame(['application/json'], $request->getHeader('Content-Type'));
    }

    public function testWithFormFieldsSetsBodyAndContentType(): void
    {
        $transport = new FakeTransport($this->okRaw());
        RequestBuilder::post('/')->withFormFields(['a' => '1', 'b' => 'x y'])->send(new Client($transport));

        $request = $transport->calls[0]['request'];
        self::assertSame('a=1&b=x+y', (string) $request->getBody());
        self::assertSame('application/x-www-form-urlencoded', $request->getHeaderLine('Content-Type'));
    }

    public function testWithMultipartSetsBodyAndContentTypeWithBoundary(): void
    {
        $transport = new FakeTransport($this->okRaw());
        RequestBuilder::post('/')->withMultipart([['name' => 'f', 'contents' => 'v']])->send(new Client($transport));

        $request = $transport->calls[0]['request'];
        self::assertStringStartsWith('multipart/form-data; boundary=', $request->getHeaderLine('Content-Type'));
        self::assertStringContainsString('name="f"', (string) $request->getBody());
    }

    public function testOptionSugarMethodsReachRequestOptions(): void
    {
        $transport = new FakeTransport($this->okRaw());
        RequestBuilder::get('/')
            ->withTimeout(5)
            ->withHttpVersion('2')
            ->send(new Client($transport));

        $options = $transport->calls[0]['options']->all();
        self::assertCount(2, $options);
    }

    public function testWithCurlOptionIsTheEscapeHatch(): void
    {
        $transport = new FakeTransport($this->okRaw());
        RequestBuilder::get('/')->withCurlOption(CURLOPT_REFERER, 'https://ref.example')->send(new Client($transport));

        self::assertSame(
            [CURLOPT_REFERER => 'https://ref.example'],
            $transport->calls[0]['options']->all()[0]->toCurlOptions()
        );
    }

    public function testDifferentCurlOptionsInOneBuilderAreBothApplied(): void
    {
        $transport = new FakeTransport($this->okRaw());
        RequestBuilder::get('/')
            ->withCurlOption(CURLOPT_CAINFO, '/etc/ssl/custom-ca.pem')
            ->withCurlOption(CURLOPT_PINNEDPUBLICKEY, 'sha256//public-key')
            ->send(new Client($transport));

        $curlOptions = [];
        foreach ($transport->calls[0]['options']->all() as $option) {
            $curlOptions += $option->toCurlOptions();
        }

        self::assertSame('/etc/ssl/custom-ca.pem', $curlOptions[CURLOPT_CAINFO]);
        self::assertSame('sha256//public-key', $curlOptions[CURLOPT_PINNEDPUBLICKEY]);
    }

    public function testWithMaxResponseSizeReachesRequestOptions(): void
    {
        $transport = new FakeTransport($this->okRaw());
        RequestBuilder::get('/')->withMaxResponseSize(1024)->send(new Client($transport));

        $curlOptions = [];
        foreach ($transport->calls[0]['options']->all() as $option) {
            $curlOptions += $option->toCurlOptions();
        }

        self::assertSame(1024, $curlOptions[CURLOPT_MAXFILESIZE]);
    }

    public function testWithoutCacheSetsFreshConnectOptionAndHeader(): void
    {
        $transport = new FakeTransport($this->okRaw());
        RequestBuilder::get('/')->withoutCache()->send(new Client($transport));

        self::assertSame('no-cache', $transport->calls[0]['request']->getHeaderLine('Cache-Control'));
        self::assertCount(1, $transport->calls[0]['options']->all());
    }

    public function testWithTransferInfoReachesRequestOptions(): void
    {
        $collector = new TransferInfoCollector();
        $transport = new FakeTransport($this->okRaw());

        RequestBuilder::get('/')->withTransferInfo($collector)->send(new Client($transport));

        self::assertSame($collector, $transport->calls[0]['options']->getTransferInfoCollector());
    }

    public function testWithStreamHandlerReachesRequestOptions(): void
    {
        $handler = new HttpStreamHandler(
            onChunk: static function (string $_): void {
            },
        );
        $transport = new FakeTransport($this->okRaw());

        RequestBuilder::get('/')->withStreamHandler($handler)->send(new Client($transport));

        self::assertSame($handler, $transport->calls[0]['options']->getStreamHandler());
    }

    public function testSendAsyncGoesThroughAsyncClient(): void
    {
        $multi = new FakeMultiTransport($this->okRaw('async-ok'));
        $response = RequestBuilder::get('/')->sendAsync(new AsyncClient($multi))->wait();

        self::assertSame('async-ok', (string) $response->getBody());
    }

    public function testSendRawReturnsRawResponseUnparsed(): void
    {
        $transport = new FakeTransport($this->okRaw('raw-ok'));
        $raw = RequestBuilder::get('/')->sendRaw($transport);

        self::assertSame('raw-ok', (string) $raw->body);
    }

    private function downloadDirectory(): string
    {
        $directory = sys_get_temp_dir() . '/curl-download-' . bin2hex(random_bytes(4));
        mkdir($directory);

        return $directory;
    }

    private function removeDirectory(string $directory): void
    {
        foreach (glob($directory . '/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($directory);
    }

    /** @param array<string, string> $headers */
    private function rawWithBody(int $status, string $body, array $headers = []): RawResponse
    {
        $block = sprintf("HTTP/1.1 %d X\r\n", $status);
        foreach ($headers as $name => $value) {
            $block .= sprintf("%s: %s\r\n", $name, $value);
        }
        $resource = fopen('php://temp', 'r+');
        fwrite($resource, $body);
        rewind($resource);

        return new RawResponse(0, '', $block . "\r\n", new Stream($resource), []);
    }

    public function testDownloadToWritesTheBodyToTheFileAndReturnsHeadersOnly(): void
    {
        $directory = $this->downloadDirectory();
        $path = $directory . '/file.bin';
        $transport = new FakeTransport($this->rawWithBody(200, 'file-bytes', ['X-Test' => '1']));

        try {
            $response = RequestBuilder::get('/file')->downloadTo($path, new Client($transport));

            self::assertSame(200, $response->getStatusCode());
            self::assertSame('1', $response->getHeaderLine('X-Test'));
            self::assertSame('', (string) $response->getBody());
            self::assertSame('file-bytes', file_get_contents($path));
            self::assertSame([$path], glob($directory . '/*'));
        } finally {
            $this->removeDirectory($directory);
        }
    }

    public function testDownloadToWritesAnErrorResponseBodyAndReturnsItsStatus(): void
    {
        $directory = $this->downloadDirectory();
        $path = $directory . '/file.bin';
        $transport = new FakeTransport($this->rawWithBody(404, 'not-found'));

        try {
            $response = RequestBuilder::get('/missing')->downloadTo($path, new Client($transport));

            self::assertSame(404, $response->getStatusCode());
            self::assertSame('not-found', file_get_contents($path));
        } finally {
            $this->removeDirectory($directory);
        }
    }

    // FakeTransport hands the body to the handler before reporting the errno, like a connection
    // that drops after some bytes arrived.
    public function testDownloadToLeavesNothingBehindWhenTheTransportFailsMidBody(): void
    {
        $directory = $this->downloadDirectory();
        $path = $directory . '/file.bin';
        $resource = fopen('php://temp', 'r+');
        fwrite($resource, 'partial');
        rewind($resource);
        $failure = new RawResponse(CURLE_RECV_ERROR, 'connection reset', "HTTP/1.1 200 OK\r\n\r\n", new Stream($resource), []);

        try {
            RequestBuilder::get('/file')->downloadTo($path, new Client(new FakeTransport($failure)));
            self::fail('Expected the transport failure to surface.');
        } catch (NetworkException $exception) {
            self::assertSame(CURLE_RECV_ERROR, $exception->getCurlErrno());
            self::assertSame([], glob($directory . '/*'));
        } finally {
            $this->removeDirectory($directory);
        }
    }

    public function testDownloadToThroughRetryClientStartsTheFileOverOnEachAttempt(): void
    {
        $directory = $this->downloadDirectory();
        $path = $directory . '/file.bin';
        $transport = new FakeTransport($this->rawWithBody(503, 'error page'), $this->rawWithBody(200, 'final'));
        $client = new RetryClient(
            new Client($transport),
            new MaxAttemptsRetryPolicy(3, [503]),
            new ExponentialBackoff(1, 1, jitter: false),
            new FakeSleeper(),
        );

        try {
            $response = RequestBuilder::get('/file')->downloadTo($path, $client);

            self::assertSame(200, $response->getStatusCode());
            self::assertCount(2, $transport->calls);
            self::assertSame('final', file_get_contents($path));
            self::assertSame([$path], glob($directory . '/*'));
        } finally {
            $this->removeDirectory($directory);
        }
    }

    public function testDownloadToRefusesToReplaceAStreamHandler(): void
    {
        $handler = new HttpStreamHandler(onChunk: static function (string $_): void {
        });

        $this->expectException(\LogicException::class);
        RequestBuilder::get('/file')->withStreamHandler($handler)->downloadTo(sys_get_temp_dir() . '/unused');
    }

    public function testEveryWithMethodReturnsANewInstance(): void
    {
        $original = RequestBuilder::get('/');
        $withHeader = $original->withHeader('X-Test', 'a');

        self::assertNotSame($original, $withHeader);
    }
}

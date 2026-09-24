<?php declare(strict_types=1);

namespace Domm98CZ\Curl;

use Domm98CZ\Curl\Contract\AsyncClientInterface;
use Domm98CZ\Curl\Contract\CurlOptionInterface;
use Domm98CZ\Curl\Contract\HttpClientInterface;
use Domm98CZ\Curl\Contract\StreamHandlerInterface;
use Domm98CZ\Curl\Contract\TransferInfoCollectorInterface;
use Domm98CZ\Curl\Contract\TransportInterface;
use Domm98CZ\Curl\Options\ConnectTimeout;
use Domm98CZ\Curl\Options\Encoding;
use Domm98CZ\Curl\Options\FreshConnect;
use Domm98CZ\Curl\Options\HttpVersion;
use Domm98CZ\Curl\Options\MaxResponseSize;
use Domm98CZ\Curl\Options\Proxy;
use Domm98CZ\Curl\Options\RawCurlOption;
use Domm98CZ\Curl\Options\RedirectPolicy;
use Domm98CZ\Curl\Options\SslVerification;
use Domm98CZ\Curl\Options\Timeout;
use Domm98CZ\Curl\Psr7\MultipartStream;
use Domm98CZ\Curl\Psr7\Request;
use Domm98CZ\Curl\Psr7\Stream;
use Domm98CZ\Curl\Psr7\Uri;
use Domm98CZ\Curl\Streaming\FileSink;
use Domm98CZ\Curl\Transport\CurlTransport;
use Http\Promise\Promise;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Http\Message\UriInterface;

final class RequestBuilder
{
    /** @var array<string, string[]> lowercase header name => values */
    private array $headers = [];

    /** @var array<string, string> lowercase header name => original case */
    private array $headerNames = [];

    private ?StreamInterface $body = null;

    /** @var list<CurlOptionInterface> */
    private array $options = [];

    private ?TransferInfoCollectorInterface $transferInfo = null;

    private ?StreamHandlerInterface $streamHandler = null;

    private string $method;
    private UriInterface $uri;

    private function __construct(string $method, UriInterface $uri)
    {
        $this->method = $method;
        $this->uri = $uri;
    }

    public static function request(string $method, UriInterface|string $uri): self
    {
        return new self($method, $uri instanceof UriInterface ? $uri : new Uri($uri));
    }

    public static function get(UriInterface|string $uri): self
    {
        return self::request('GET', $uri);
    }

    public static function post(UriInterface|string $uri): self
    {
        return self::request('POST', $uri);
    }

    public static function put(UriInterface|string $uri): self
    {
        return self::request('PUT', $uri);
    }

    public static function patch(UriInterface|string $uri): self
    {
        return self::request('PATCH', $uri);
    }

    public static function delete(UriInterface|string $uri): self
    {
        return self::request('DELETE', $uri);
    }

    public static function head(UriInterface|string $uri): self
    {
        return self::request('HEAD', $uri);
    }

    public static function options(UriInterface|string $uri): self
    {
        return self::request('OPTIONS', $uri);
    }

    public function withHeader(string $name, string $value): self
    {
        $new = clone $this;
        $lower = strtolower($name);
        $new->headerNames[$lower] ??= $name;
        $new->headers[$lower] = [$value];
        return $new;
    }

    public function withAddedHeader(string $name, string $value): self
    {
        $new = clone $this;
        $lower = strtolower($name);
        $new->headerNames[$lower] ??= $name;
        $new->headers[$lower][] = $value;
        return $new;
    }

    public function withBody(StreamInterface $body): self
    {
        $new = clone $this;
        $new->body = $body;
        return $new;
    }

    /** @param array<string, mixed> $queryParams merged into the URI's existing query string, an existing pair with the same key is replaced */
    public function withQueryParams(array $queryParams): self
    {
        $added = http_build_query($queryParams, '', '&', PHP_QUERY_RFC3986);
        $replacedKeys = [];
        foreach (self::queryPairs($added) as [$key]) {
            $replacedKeys[$key] = true;
        }

        $pairs = [];
        foreach (self::queryPairs($this->uri->getQuery()) as [$key, $pair]) {
            if (!array_key_exists($key, $replacedKeys)) {
                $pairs[] = $pair;
            }
        }
        if ($added !== '') {
            $pairs[] = $added;
        }

        $new = clone $this;
        $new->uri = $this->uri->withQuery(implode('&', $pairs));
        return $new;
    }

    // Existing pairs are kept byte for byte; only the key is decoded, for the comparison. parse_str()
    // is avoided on purpose: it rewrites dots and spaces in keys and folds brackets into arrays.
    /** @return list<array{string, string}> decoded key and the raw pair */
    private static function queryPairs(string $query): array
    {
        $pairs = [];
        foreach (explode('&', $query) as $pair) {
            if ($pair === '') {
                continue;
            }
            $pairs[] = [rawurldecode(explode('=', $pair, 2)[0]), $pair];
        }
        return $pairs;
    }

    public function withAccept(string $mediaType): self
    {
        return $this->withHeader('Accept', $mediaType);
    }

    public function withJson(mixed $data): self
    {
        $new = $this->withBody($this->streamFrom(json_encode($data, JSON_THROW_ON_ERROR)));
        return $new->withHeader('Content-Type', 'application/json');
    }

    /** sends the string as given; serialize a DOMDocument with saveXML() first */
    public function withXml(string $xml): self
    {
        return $this->withBody($this->streamFrom($xml))->withHeader('Content-Type', 'application/xml');
    }

    /** @param array<string, string> $fields */
    public function withFormFields(array $fields): self
    {
        $new = $this->withBody($this->streamFrom(http_build_query($fields)));
        return $new->withHeader('Content-Type', 'application/x-www-form-urlencoded');
    }

    /** @param array<int, array{name: string, contents: StreamInterface|string, filename?: ?string, headers?: array<string, string>}> $parts */
    public function withMultipart(array $parts): self
    {
        $multipart = new MultipartStream($parts);
        $new = $this->withBody($multipart);
        return $new->withHeader('Content-Type', $multipart->getContentType());
    }

    public function withTimeout(int $seconds): self
    {
        return $this->withOption(new Timeout($seconds));
    }

    public function withConnectTimeout(int $seconds): self
    {
        return $this->withOption(new ConnectTimeout($seconds));
    }

    public function withMaxResponseSize(int $bytes): self
    {
        return $this->withOption(new MaxResponseSize($bytes));
    }

    public function withEncoding(string $encoding = ''): self
    {
        return $this->withOption(new Encoding($encoding));
    }

    public function withHttpVersion(string $version): self
    {
        return $this->withOption(new HttpVersion($version));
    }

    public function withSslVerify(bool $verifyPeer = true, bool $verifyHost = true): self
    {
        return $this->withOption(new SslVerification($verifyPeer, $verifyHost));
    }

    public function withRedirects(bool $follow = true, int $maxRedirects = 20): self
    {
        return $this->withOption(new RedirectPolicy($follow, $maxRedirects));
    }

    public function withProxy(string $url): self
    {
        return $this->withOption(new Proxy($url));
    }

    public function withoutCache(): self
    {
        return $this->withOption(new FreshConnect(true))->withHeader('Cache-Control', 'no-cache');
    }

    public function withCurlOption(int $option, mixed $value): self
    {
        return $this->withOption(new RawCurlOption($option, $value));
    }

    public function withOption(CurlOptionInterface $option): self
    {
        $new = clone $this;
        $new->options[] = $option;
        return $new;
    }

    public function withTransferInfo(TransferInfoCollectorInterface $collector): self
    {
        $new = clone $this;
        $new->transferInfo = $collector;
        return $new;
    }

    /** attaching a handler hands the body to it chunk by chunk; the returned response then carries headers only */
    public function withStreamHandler(StreamHandlerInterface $handler): self
    {
        $new = clone $this;
        $new->streamHandler = $handler;
        return $new;
    }

    public function getUri(): UriInterface
    {
        return $this->uri;
    }

    /** the PSR-7 request send() would put on the wire, for inspection without sending */
    public function getRequest(): RequestInterface
    {
        return $this->buildRequest();
    }

    public function send(?HttpClientInterface $client = null): ResponseInterface
    {
        return ($client ?? new Client())->send($this->buildRequest(), $this->buildOptions());
    }

    public function sendAsync(?AsyncClientInterface $asyncClient = null): Promise
    {
        return ($asyncClient ?? new AsyncClient())->sendAsync($this->buildRequest(), $this->buildOptions());
    }

    public function sendRaw(?TransportInterface $transport = null): RawResponse
    {
        return ($transport ?? new CurlTransport())->execute($this->buildRequest(), $this->buildOptions());
    }

    /** streams the body into $path (any status, 4xx/5xx included); the response carries headers only */
    public function downloadTo(string $path, ?HttpClientInterface $client = null): ResponseInterface
    {
        if ($this->streamHandler !== null) {
            throw new \LogicException('downloadTo() owns the body; it cannot be combined with withStreamHandler().');
        }

        $sink = new FileSink($path);
        try {
            $response = $this->withStreamHandler($sink)->send($client);
        } catch (\Throwable $exception) {
            $sink->discard();
            throw $exception;
        }
        $sink->commit();

        return $response;
    }

    private function buildRequest(): RequestInterface
    {
        return new Request(
            $this->method,
            $this->uri,
            $this->buildHeaders(),
            $this->body ?? new Stream(self::openTempStream())
        );
    }

    /** @return array<string, string[]> */
    private function buildHeaders(): array
    {
        $headers = [];
        foreach ($this->headers as $lower => $values) {
            $headers[$this->headerNames[$lower]] = $values;
        }
        return $headers;
    }

    private function buildOptions(): RequestOptions
    {
        return new RequestOptions($this->options, $this->transferInfo, $this->streamHandler);
    }

    private function streamFrom(string $contents): Stream
    {
        $resource = self::openTempStream();
        fwrite($resource, $contents);
        rewind($resource);
        return new Stream($resource);
    }

    /** @return resource */
    private static function openTempStream()
    {
        $resource = fopen('php://temp', 'r+');
        if ($resource === false) {
            throw new \RuntimeException('Unable to open "php://temp" stream.');
        }
        return $resource;
    }
}

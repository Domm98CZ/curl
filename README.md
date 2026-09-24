# curl

[![Build Status](https://img.shields.io/github/actions/workflow/status/Domm98CZ/curl/ci.yml?branch=v1-beta)](https://github.com/Domm98CZ/curl/actions/workflows/ci.yml) [![Latest Version](https://img.shields.io/packagist/v/domm98cz/curl)](https://packagist.org/packages/domm98cz/curl) [![PHP Version](https://img.shields.io/packagist/php-v/domm98cz/curl)](https://packagist.org/packages/domm98cz/curl) [![License](https://img.shields.io/github/license/Domm98CZ/curl)](LICENSE) [![Downloads](https://img.shields.io/packagist/dt/domm98cz/curl)](https://packagist.org/packages/domm98cz/curl)

Lightweight PSR-7 / PSR-17 / PSR-18 compliant HTTP client for PHP 8.1+, built on `ext-curl`. Fluent builder on top, zero PSR-7 implementation dependency underneath.

## Install

```bash
composer require domm98cz/curl
```

## Quick start

```php
use Domm98CZ\Curl\RequestBuilder;

$response = RequestBuilder::get('https://api.example.com/users')
    ->withHeader('Accept', 'application/json')
    ->withTimeout(5)
    ->send();

$response->getStatusCode();
(string) $response->getBody();
```

A request carrying a credential header (`Authorization`, an API key, anything outside the safelist in [Redirects](#redirects)) is not redirected automatically; you get the 3xx back. Runnable scripts for every feature below are in [`examples/`](examples/).

## PSR-18

`Client` implements `Psr\Http\Client\ClientInterface` directly:

```php
use Domm98CZ\Curl\Client;
use Domm98CZ\Curl\Psr7\Request;

$client = new Client();
$response = $client->sendRequest(new Request('GET', 'https://api.example.com'));
```

4xx/5xx responses are returned as an ordinary `ResponseInterface`, never an exception. Exceptions are reserved for transport-level failures (DNS, connection refused, timeout, TLS) and for the local failures listed below; all of them implement `Psr\Http\Client\ClientExceptionInterface`.

`Client` accepts only `http` and `https`: any other scheme is refused with a `RequestException` before a connection is opened, and the transfer is pinned to HTTP(S) so a redirect cannot escalate into `file://`, `ftp://` or any other protocol the local libcurl supports. The client reads the request and URI more than once and relies on the immutability PSR-7 requires; implementations whose accessors change between calls are not supported inputs. Responses are transparently decompressed (`gzip`/`deflate`/`br`); `Content-Encoding` is dropped and `Content-Length` rewritten to describe what `getBody()` returns. Attach `Options\Encoding` to override that.

`Domm98CZ\Curl\Contract\HttpClientInterface` extends the PSR-18 interface with `send(RequestInterface, ?OptionsInterface)`. Both `Client` and `RetryClient` implement it, and `RequestBuilder::send()` accepts any implementation, so per-request options survive being wrapped in middleware.

### Redirects

Redirects are followed by default (up to 20 hops); an `https` request can only redirect to another `https` URL. Three guards switch automatic following off for a single request, in which case you receive the 3xx response and decide what to send next:

- **Credentials.** Any header outside this safelist counts as a credential: `Accept`, `Accept-Charset`, `Accept-Encoding`, `Accept-Language`, `Cache-Control`, `Connection`, `Content-Length`, `Content-Type`, `Expect`, `If-Match`, `If-Modified-Since`, `If-None-Match`, `If-Range`, `If-Unmodified-Since`, `Pragma`, `Range`, `Referer`, `TE`, `User-Agent`. So do userinfo in the URI and a caller-supplied `Host` that differs from the URI. Opt back in with `new RedirectPolicy(followWithCredentials: true)`.
- **Pinned request target.** An explicit `withRequestTarget()` differing from the URI path would be replayed unchanged against whatever host the redirect names. Opt back in with `followWithPinnedRequestTarget: true`.
- **Streamed body.** A body larger than 1 MiB or of unknown size cannot be replayed to the next hop. No opt-in; a `HEAD` sends no body and is never affected.

Each opt-in lifts only its own guard. On a followed cross-host redirect libcurl strips `Authorization` and `Cookie` but replays every other header, so `followWithCredentials: true` alone is enough to leak an API-key header cross-origin. **Enabling both opt-ins at once is the most dangerous state this library can reach**: a pinned target carrying a secret replays unchanged against whatever origin the redirect names. Use `new RedirectPolicy(follow: false)` to disable redirects entirely.

`RedirectPolicy` is a value the option mapper interprets together with the guards it detected. `RawCurlOption(CURLOPT_FOLLOWLOCATION, ...)` placed **after** the policy reaches curl untouched and is the low-level escape hatch, including for the streamed-body guard; placed before it, it is overwritten like any earlier write.

### Options

Every option is a `Contract\CurlOptionInterface` whose `toCurlOptions()` returns the `CURLOPT_*` keys it writes. `RequestOptions` applies options in insertion order; when two write the same key the later one wins and nothing is discarded, so order is security-relevant whenever `withCurlOption()` touches certificate pinning or another sensitive key. The one exception is the response size counter, which reads both ceiling keys (see [Transfer limits](#transfer-limits)).

### Exceptions

Transport failures carry the raw curl errno:

```php
try {
    $client->sendRequest($request);
} catch (\Domm98CZ\Curl\Exceptions\NetworkException $exception) {
    if ($exception->getCurlErrno() === CURLE_OPERATION_TIMEDOUT) {
        // distinguishable from CURLE_COULDNT_CONNECT without parsing the message
    }
}
```

Two local failures are not `NetworkException`s and implement `Contract\NonRetryableExceptionInterface`, so `RetryClient` never replays them: `Exceptions\ResponseBufferException` (the library's own `php://temp` response buffer refused a write, for example a full disk; the transfer itself succeeded, and a PHP warning turned into a `\Throwable` by your error handler is available through `getPrevious()`) and `Exceptions\ResponseSizeException` (a `MaxResponseSize` ceiling stopped the response). Both share errno 23 with a genuine write error, so catch the type, not the errno.

## Transfer limits

Connection establishment has a 30-second default limit (`ConnectTimeout(0)` restores libcurl's own). The total transfer time is unlimited unless `Timeout`/`withTimeout()` is attached, so long-lived SSE and streaming responses are not cut off by default.

Response size is opt-in:

```php
$response = RequestBuilder::get('https://api.example.com/export')
    ->withMaxResponseSize(10 * 1024 * 1024)
    ->send();
```

`MaxResponseSize` is one byte ceiling on the decoded body of the final response plus the response headers of every hop, including blocks you never see (`100 Continue`, a proxy's `407`, intermediate redirects). Crossing it throws `ResponseSizeException` (errno 23 from the library's counter, or 63 when libcurl refuses an advertised `Content-Length` up front, `HEAD` included). The chunk that crosses the ceiling is never buffered or handed to a stream handler, though libcurl may deliver up to 16 KiB past the threshold before the abort.

Attach `MaxResponseSize` last if anything earlier in the chain also writes `CURLOPT_MAXFILESIZE`: a later write overrides it, and the counter honours the lower of `CURLOPT_MAXFILESIZE`/`CURLOPT_MAXFILESIZE_LARGE`. A ceiling that cannot be read as a positive whole number of bytes is a configuration error and raises a `RequestException` (`UPGRADE.md` has the exact rules).

## JSON, forms, multipart

```php
use Domm98CZ\Curl\Psr17\StreamFactory;

RequestBuilder::post('https://api.example.com/users')->withJson(['name' => 'Ada'])->withAccept('application/json')->send();

RequestBuilder::post('https://api.example.com/users')->withXml($document->saveXML())->send(); // string in, application/xml out

RequestBuilder::post('https://api.example.com/login')->withFormFields(['user' => 'ada'])->send();

RequestBuilder::post('https://api.example.com/upload')->withMultipart([
    ['name' => 'file', 'contents' => (new StreamFactory())->createStreamFromFile('photo.jpg'), 'filename' => 'photo.jpg'],
])->send();
```

Part names, filenames and part headers reject CR, LF, NUL, quotes and backslashes with an `InvalidArgumentException`, as do header names/values and the HTTP method (an RFC 9110 token). The multipart body is assembled up front into `php://temp`; a part stream that cannot be read throws its `RuntimeException` instead of yielding an empty part. `withQueryParams()` replaces existing pairs with the same key, keeps every other pair byte for byte and percent-encodes new values per RFC 3986. `getUri()` and `getRequest()` show what `send()` would put on the wire without sending it.

Bodies up to 1 MiB are handed to libcurl whole (and can be replayed after a 307/308); larger or unknown-size bodies are streamed. Buffered bodies are sent without `Expect: 100-continue`, so a server that never answers with an interim response does not cost a wait per request; streamed bodies keep libcurl's default, which lets a server reject a large upload before it starts. Set the `Expect` header yourself to override either.

Headers: `withHeader($name, [])` removes a header and `withAddedHeader($name, [])` is a no-op, while an empty string value is sent as a present-but-empty header (`Authorization;` in curl syntax). `Content-Length` and `Transfer-Encoding` set on the message never reach the wire: libcurl frames the body itself, and forwarding a value that disagrees with the body would be a request-smuggling primitive. `Host` must carry exactly one non-empty value. Use `RawCurlOption`/`withCurlOption()` to own framing yourself.

An explicit `withRequestTarget()` is honoured on the wire (`OPTIONS *`, absolute-form for proxies), requires libcurl 7.55.0+, and disables automatic redirects (see above).

**`Uri::getPath()` and `Request::getRequestTarget()` collapse a leading `//` to `/`; the wire request does not.** `https://good.example//evil.example/x` reports `/evil.example/x` from both accessors (a PSR-7 conformance requirement) but is sent as `GET //evil.example/x`, which an intermediary may read as protocol-relative. An SSRF allowlist must validate the serialized URI or the actual request line, not `getPath()`.

Validation is strict on the way out only: `Response::withStatus()` accepts `100`-`999` and rejects CR/LF/NUL in a reason phrase, while a response parsed off the wire stays tolerant (a malformed header line is skipped, any status code is accepted) so an unusual server cannot turn into an exception escaping `sendRequest()`.

## Streaming responses (SSE, chunked LLM output)

Attach a stream handler to receive the body while it is still arriving:

```php
use Domm98CZ\Curl\RequestBuilder;
use Domm98CZ\Curl\Streaming\HttpStreamHandler;
use Psr\Http\Message\ResponseInterface;

$handler = new HttpStreamHandler(
    onChunk: function (string $chunk): void {
        echo $chunk;
    },
    onResponse: function (ResponseInterface $response): void {
        // status and headers, before the first body byte
        if (!str_starts_with($response->getHeaderLine('Content-Type'), 'text/event-stream')) {
            // ...
        }
    },
);

$response = RequestBuilder::get('https://api.example.com/events')
    ->withStreamHandler($handler)
    ->send();
```

- **A handler means you own the body.** The transport stops buffering and the returned `ResponseInterface` carries status and headers only.
- `CURLOPT_WRITEFUNCTION` and `CURLOPT_HEADERFUNCTION` are transport-owned; supplying either through `withCurlOption()` throws a `RequestException`.
- `onResponse` fires once per header block, so a followed redirect calls it once per hop.
- Implement `Contract\StreamHandlerInterface` directly (`onHeaderLine()`/`onChunk()`) for the raw header lines.

The library does not parse Server-Sent Events, reconnect a dropped stream or track `Last-Event-ID`; that belongs to the consumer.

### Downloading to a file

```php
$response = RequestBuilder::get('https://example.com/archive.zip')->downloadTo('/tmp/archive.zip');
```

`downloadTo()` streams the body into a part file next to the target and moves it into place only once the transfer completed, so the target path never holds a partial file: a transport failure leaves nothing behind and rethrows, an existing file is replaced, and an error status (404, 500) is stored like any other body and reported through the returned headers-only response. Under `RetryClient` each attempt starts the file over. The same `Streaming\FileSink` works with `AsyncClient` as a stream handler; call `commit()` after `wait()` (or `discard()` on failure) yourself.

## Timing

```php
use Domm98CZ\Curl\{RequestBuilder, TransferInfoCollector};

$collector = new TransferInfoCollector();
RequestBuilder::get('https://api.example.com')->withTransferInfo($collector)->send();

$collector->get()->startTransferTimeMs; // TTFB
$collector->get()->totalTimeMs;
```

One collector per request: reusing it throws (`examples/transfer-info.php`).

## Retry

```php
use Domm98CZ\Curl\Client;
use Domm98CZ\Curl\Middleware\RetryClient;
use Domm98CZ\Curl\Psr7\Request;
use Domm98CZ\Curl\Retry\{MaxAttemptsRetryPolicy, ExponentialBackoff};

$client = new RetryClient(new Client(), new MaxAttemptsRetryPolicy(3), new ExponentialBackoff());
$response = $client->sendRequest(new Request('GET', 'https://api.example.com'));

$client = RetryClient::withDefaults(new Client()); // 3 attempts on 429/502/503/504 and transport failures
```

`MaxAttemptsRetryPolicy` replays only idempotent methods (GET, HEAD, PUT, DELETE, OPTIONS, TRACE) unless you pass `retryNonIdempotentMethods: true`, and never replays a `RequestExceptionInterface` or a `NonRetryableExceptionInterface`. A `Retry-After` header (delta-seconds or HTTP-date) takes precedence over the backoff delay up to `maxRetryAfterSeconds` (60). `ExponentialBackoff` applies full jitter by default.

A retry is fail-closed. A stream handler or `TransferInfoCollector` attached to the request must implement `Contract\RetryAwareInterface` and approve the reset (`HttpStreamHandler` takes an `onRetry` closure), and the request body must be replayable: a non-seekable body, or any option writing a body key (`CURLOPT_POSTFIELDS`, `CURLOPT_INFILE`, `CURLOPT_READFUNCTION`, `CURLOPT_UPLOAD`, `CURLOPT_CUSTOMREQUEST` and the rest of that family), makes `RetryClient` throw a `ClientException` that preserves the triggering cause instead of sending a second attempt with different bytes. `RetryClient` asks the client it decorates through the opt-in `Contract\ReplayAwareInterface` and falls back to a seekability check when the client cannot answer.

## Async and pooling

```php
use Domm98CZ\Curl\AsyncClient;
use Domm98CZ\Curl\Options\Timeout;
use Domm98CZ\Curl\Pool;
use Domm98CZ\Curl\RequestOptions;

$response = RequestBuilder::get('https://api.example.com')->sendAsync()->wait();

$requestA = new \Domm98CZ\Curl\Psr7\Request('GET', 'https://api.example.com/a', ['Accept' => 'application/json']);
$results = (new Pool())->send([$requestA, $requestB, $requestC], concurrency: 5, options: new RequestOptions([new Timeout(15)]));

$async = new AsyncClient();
$promises = [
    $async->sendAsync($requestA, $optionsA),
    $async->sendAsync($requestB, $optionsB),
];
$responses = array_map(static fn ($promise) => $promise->wait(), $promises);
```

`sendAsync()` returns an `Http\Promise\Promise`. `then()` returns a new, already-resolved promise carrying its callback's return value (an `$onRejected` callback that returns normally recovers from the rejection), and a callback that reads the body exhausts the same stream `wait()` on the original promise returns.

`Pool` keeps at most `concurrency` transfers in flight and refills a slot the moment its transfer finishes. The optional `options` are shared by the whole batch (`Options\*` only: a stream handler or a `TransferInfoCollector` is per-transfer state and is refused); use `AsyncClient::sendAsync($request, $options)` and `wait()` when each request needs its own (`examples/pool.php`, `examples/pool-shared-options.php`). Every input produces a value under its own key: library failures, including non-HTTP schemes, are returned as `ClientExceptionInterface` values instead of aborting the batch, an exception from your own callback keeps its type and identity, and an `\Error` propagates out of `send()`.

## Non-HTTP protocols

`send()` is HTTP(S)-only. `sendRaw()` uses the same transport for anything else `ext-curl` supports (FTP, FTPS, SMTP, SFTP) and returns a protocol-agnostic `RawResponse`:

```php
$raw = RequestBuilder::request('GET', 'ftp://example.com/file.txt')->sendRaw();
```

`sendRaw()` never throws for a transport failure: check `isTransportError()` (and `$responseSizeExceeded` for a size-ceiling abort) yourself. Schemes the library knows are pinned to their protocol; others use libcurl's defaults. Never hand `sendRaw()` a URI built from untrusted input.

## Contributing / running tests

```bash
docker compose build
docker compose run --rm php composer install
docker compose run --rm php vendor/bin/phpunit
docker compose run --rm php vendor/bin/phpstan analyse --memory-limit=512M
```

The library supports PHP 8.1 through 8.5; the development image pins PHP 8.4 and CI runs the suite on 8.1, 8.2, 8.3, 8.4 and 8.5, plus a blocking `prefer-lowest` job on PHP 8.1.

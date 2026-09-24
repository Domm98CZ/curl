# Upgrading to 1.0

Version 1.0 is a complete, breaking rewrite of `domm98cz/curl`. The old
`Domm98CZ\CurlClient\CurlClient` class has been deleted entirely — there is
no compatibility shim, and no gradual migration path. Upgrading means
rewriting call sites against the new PSR-7 / PSR-17 / PSR-18 compliant API.

## Before you start

- **PHP 8.1 is still the floor** — unchanged from 0.4.x, so this rewrite introduces no new
  PHP version requirement. What's new is the upper bound: `>=8.1 <8.6` instead of an open-ended
  `>=8.1`. CI runs the full test suite on 8.1 — it is genuinely tested, not just declared. That
  said, PHP 8.1 itself has reached end of life upstream and no longer receives security fixes
  from the PHP project; running 8.2 or later is recommended where that's an option.
- **`psr/http-message` `^2.0` is required.** This is the most common install-time
  failure: `guzzlehttp/psr7` below `2.5` (and anything else still pinned to
  `psr/http-message ^1.0`) conflicts with it. Upgrade those packages first, or
  Composer will refuse to resolve.
- The package advertises `psr/http-client-implementation`,
  `psr/http-factory-implementation` and `psr/http-message-implementation`, so
  discovery-based packages can pick it up.

The table below maps each piece of the old API to its new equivalent.

| Old | New | Note |
|---|---|---|
| `createRequest()` query args + `doUseUrlEncode` | `Uri` / query builder | Proper key+value encoding, unlike old manual `sprintf` concatenation |
| HTTP methods (GET/POST/PUT/HEAD/DELETE/PATCH/OPTIONS) | `RequestBuilder`/`Request::getMethod()` | Any RFC 9110 token, including extension methods such as `PROPFIND`; non-token strings are rejected |
| `setHeaders()`/`addHeaders()` | PSR-7 `withHeader()`/`withAddedHeader()` | Native multi-value support |
| SSL verify host/peer, `setCert()` | `SslVerification` option component | |
| `setTimeout()` (shared connect+total) | `Timeout` + `ConnectTimeout` components | Split for finer control; to reproduce the old combined behavior yourself, pass the same value to both — this is a migration recipe, not this library's own default (its own default is different, see below) |
| `setCustomOptions()` | `withCurlOption()` escape hatch | |
| `setUseCache()` / no-cache header + `FRESH_CONNECT` | `FreshConnect` option component | |
| `getResponseHeader()` / `parseHttpResponseHeaders()` | PSR-7 `Response::getHeaders()` | Native multi-value, no bespoke parser needed |
| `getHttpCode()` | `Response::getStatusCode()` | |
| `getCurlInfo()` | `TransferInfoCollector` | Moved off the response object to respect the PSR-18 signature; now also carries TTFB (`startTransferTimeMs`) |
| `getCurlError()` / thrown on failure | `NetworkException`/`RequestException` | Only for transport failures now, not HTTP error statuses (see below); `getCurlErrno()` exposes the raw curl errno |
| `CURLOPT_FAILONERROR` | *(not set)* | Intentional behavior change, required by PSR-18 compliance |
| `CURLOPT_FOLLOWLOCATION` (always true) | `RedirectPolicy` component | Still defaults to following; HTTPS cannot downgrade to HTTP, while HTTP can upgrade to HTTPS. `followWithCredentials` originally also restored following after a pinned request target; that case now needs its own `followWithPinnedRequestTarget` opt-in — see "Behaviour worth knowing about" below |
| Protocol constants (ftp/ftps/smtp/ntp) — never actually used in logic | `RequestBuilder::sendRaw()` | Kept as a supported use case, outside the PSR-18 contract |
| `debug()` (Tracy/Symfony VarDumper/var_dump) | *(removed)* | Dump the PSR-7 objects with whatever debugger your application already uses |

## Error handling change

The old client threw on any non-2xx HTTP response (via `CURLOPT_FAILONERROR`).
The new `Client`/`AsyncClient` follow PSR-18 strictly: exceptions are thrown
**only** for transport-level failures (DNS resolution, connection refused,
timeout, TLS handshake failure, malformed request). HTTP error statuses
(4xx/5xx) are returned as an ordinary `ResponseInterface` with that status
code — callers must inspect `getStatusCode()` themselves instead of relying
on a thrown exception. See the README's "PSR-18" section for details.

A response that cannot be parsed as HTTP at all (no recognisable status line)
is a failure, not a `200` — it throws a `ClientException`, which implements
`Psr\Http\Client\ClientExceptionInterface`. Failures raised by the library always implement
`ClientExceptionInterface`. An exception raised by your own callback normally escapes with its
original type and identity. The exception is a `\RuntimeException` raised while reading the request
body, as prescribed by PSR-7 for `StreamInterface::read()` failures: it is wrapped in a
`RequestException`, with the original exception available through `getPrevious()`.

A related but distinct failure is buffering the response **locally**: `Exceptions\ResponseBufferException`
(a `ClientException`, so still `ClientExceptionInterface`) is thrown when the library's own
`php://temp` response buffer refuses a write — for example because the buffer has spilled to disk
past its ~2 MB threshold and the temp directory turns out to be unwritable or missing, or the
disk is full. The message deliberately says the transfer itself did not
fail — only the local buffer did — so don't go looking for a network problem that isn't there; it
may also include a hint from `error_get_last()`, explicitly marked as possibly unrelated. If
PHP raised a warning for the failed write and your error handler turned it into a `\Throwable`
(many framework error handlers do), that original exception is available through `getPrevious()`.

`Exceptions\ResponseSizeException` is thrown when `MaxResponseSize` stops a response. It is a
library-policy failure, not a `NetworkException`, and preserves the originating request through
`getRequest()`. A callback abort can carry `CURLE_WRITE_ERROR` (23), the same errno as a local
response-buffer failure, so errno alone does not classify either condition.

`RequestBuilder::sendRaw()` sits outside this exception mapping entirely — it always returns a `RawResponse`, never throws for a transport-level failure. For that caller, `RawResponse::$responseSizeExceeded` is the only signal that a `MaxResponseSize` ceiling stopped the transfer: `errno` alone (23 or 63) is shared with other transport failures and does not identify it. The flag is meaningful only once `isTransportError()` is true (`$errno !== 0`); both transports force a nonzero `errno` whenever the ceiling was crossed, so this case never reports `errno === 0`.

`ResponseBufferException` and `ResponseSizeException` implement `Contract\NonRetryableExceptionInterface`,
a marker interface with no methods that asserts exactly one thing: replaying the request will not
help. `MaxAttemptsRetryPolicy` checks for it alongside `RequestExceptionInterface` and skips the
retry. Neither implements `RequestExceptionInterface`: that interface's PSR-18
semantics are "the request is unusable," which would be false here. A third-party
`RetryPolicyInterface` implementation will not honor `NonRetryableExceptionInterface` unless it
explicitly checks for it.

## Multi-transport port contract

Implementations of `Contract\MultiTransportInterface` must retain a terminal handle until its
result is consumed. `isDone()` now throws for an unknown or already-consumed handle, while
`takeResult()` consumes the handle and may rethrow any `\Throwable` stored for it. Both operations
are single-use at the transport boundary; callers are responsible for memoizing the terminal result
before calling either operation again. This is a breaking change for custom implementations of the
port and their test doubles.

The port now also requires an idempotent `release(int $id): void` operation for abandoned handles.
Dropping a still-pending, unconsumed promise calls it and cancels that promise's transfer; before this
change, the transfer and its retained resources continued until the transport itself was destroyed.

## Behaviour worth knowing about

- **Connection establishment now has a 30-second default timeout.** This applies to every
  supported scheme. Attach `ConnectTimeout(0)` to restore libcurl's own default. There is no
  default total-transfer timeout; attach `Timeout` explicitly when the complete transfer needs
  a deadline.
- **Request options are an ordered list.** A `CurlOptionInterface` returns the `CURLOPT_*` keys it
  writes from `toCurlOptions()`; every option is applied in insertion order and a later write to
  the same key wins, with no exception. Multiple `RawCurlOption` instances no longer collapse into
  one entry merely because they share a class.
- **`CURLOPT_WRITEFUNCTION` and `CURLOPT_HEADERFUNCTION` are reserved.** Supplying either through
  `withCurlOption()` now throws a `RequestException` instead of being silently overwritten. Use a
  `StreamHandler` through `withStreamHandler()` to consume response headers or body chunks.
- **`Pool` keeps `concurrency` transfers in flight and refills a slot as soon as its transfer
  finishes.** It runs over `Contract\MultiTransportInterface` directly (constructor: transport,
  response parser) rather than over an `AsyncClientInterface`. `send()` takes an optional third
  argument, `OptionsInterface` shared by every transfer of the batch; it may carry `Options\*`
  only, a stream handler or a transfer info collector in it is refused with an
  `InvalidArgumentException` before anything is sent. Failures are returned under their
  request key: an unsupported scheme no longer aborts `Pool::send()`, library failures are always
  `ClientExceptionInterface` values, and an exception raised by your own callback keeps its original
  type and identity under that callback's request key. `Pool` deliberately catches `\Exception`, not
  `\Error`: an `\Error` from a consumer callback still propagates out of `send()`, so no result array
  is returned.
- **Redirects carrying caller-supplied credentials are no longer followed automatically.**
  This includes `Authorization`, `Cookie`, API-key headers, and unknown custom headers. The
  caller receives the 3xx response instead, even for a same-origin redirect. Pass
  `new RedirectPolicy(followWithCredentials: true)` to retain the old unconditional behavior;
  the README lists the complete safelist of headers that do not trigger this protection.
  `followWithCredentials` only lifts this guard: it does not also restore following after a
  pinned request target (see the `withRequestTarget()` bullet below) or for a streamed body,
  each of which is its own guard with its own opt-in, or none at all.
- **`RedirectPolicy` is a plain value and the option mapper decides.** The policy writes only
  `CURLOPT_MAXREDIRS` itself; `CURLOPT_FOLLOWLOCATION` is written by the mapper from the policy and
  the redirect guards it detected (`Domm98CZ\Curl\RedirectGuards`), at the policy's position in
  the option list. A `RawCurlOption(CURLOPT_FOLLOWLOCATION, ...)` after the policy passes through
  untouched; before it, the policy's verdict overwrites it like any earlier write. `RedirectGuards`
  is a value object, not a `CurlOptionInterface`; attaching it to `RequestOptions` throws a
  `TypeError`.
- **Non-HTTP schemes are refused by `send()`/`sendRequest()`.** `file://`,
  `ftp://`, `gopher://` and friends throw a `RequestException`. Use
  `RequestBuilder::sendRaw()` if you actually want them.
- **Responses are decompressed by default.** `getBody()` returns decoded bytes
  and the `Content-Encoding` header is removed, with `Content-Length` rewritten
  to match. If you were decompressing yourself, stop — or attach
  `Options\Encoding` to take control.
- **Streamed POST bodies now use `CURLOPT_UPLOAD` with `CURLOPT_CUSTOMREQUEST` set to
  `POST`.** The HTTP request remains a `POST` with a `Content-Length`, but libcurl no longer
  adds `Content-Type: application/x-www-form-urlencoded` as it did for `CURLOPT_POST`.
  Applications relying on that implicit value must set `Content-Type` explicitly.
- **`Options\MaxResponseSize` now counts response headers against the same ceiling as the
  final response body.** A response with a large header block that previously succeeded can
  now fail with `Maximum response size of N bytes exceeded.`; the ceiling covers the final
  response body plus the headers of every redirect hop. Header blocks the consumer never sees
  count too — a `100 Continue`, a proxy's `407` challenge, an intermediate hop — so the ceiling
  can be exhausted before a single visible byte arrives. Two errno values reach the same
  `ResponseSizeException`: 23 when the library's own decoded-byte counter aborts the transfer,
  63 when libcurl's pre-flight refuses an advertised `Content-Length` (also for a `HEAD`, whose
  advertised length is compared even though no body follows; a compressed resource whose wire
  size fits is only stopped once a `GET` decodes it). The ceiling is read back from
  `CURLOPT_MAXFILESIZE`/`CURLOPT_MAXFILESIZE_LARGE`, enforcing the lower positive value when
  both are set; disabling it needs both keys absent, `null`, `false` or numerically exactly zero
  (`0`, `'0'`, `0.0`, `' 0 '`). A present value that is not numeric (`''`, `'10M'`, `true`) or
  that truncates to zero bytes without being zero (`0.5`, `'1e-3'`) raises a `RequestException`
  before the request is sent, because libcurl would otherwise coerce it to `0` and switch the
  ceiling off silently.
- **Buffered request bodies are sent without `Expect: 100-continue`, whatever libcurl's own
  threshold is.** libcurl adds the header on its own above a size threshold that has changed
  between releases (1 KiB historically, 1 MiB on current builds) and then waits up to a second
  for an interim response that many servers never send. Bodies the mapper hands to libcurl
  whole (up to 1 MiB) now carry an explicit `Expect:` removal, so the behaviour no longer
  depends on the libcurl version; streamed bodies keep libcurl's default so a server can still
  reject a large upload before it starts (attach `RawCurlOption(CURLOPT_EXPECT_100_TIMEOUT_MS, 0)`
  to shorten that wait). A caller-supplied `Expect` header is forwarded unchanged in both cases.
- **Retries are restricted to idempotent methods.** A `RetryClient` no longer
  replays POST/PATCH unless you pass
  `new MaxAttemptsRetryPolicy(retryNonIdempotentMethods: true)`.
- **`RetryClient` refuses to retry a request whose body it cannot replay.** A non-seekable body
  now produces a `ClientException` instead of a second attempt. Previously the retry went ahead
  over an already consumed stream, put an empty body on the wire and returned the server's
  response to it as if the payload had been delivered. Wrap the body in a seekable stream if you
  need the retry.
- **A non-seekable body is refused whatever size it reports, including zero.** This heuristic runs
  only where the decorated client cannot say what it puts on the wire — a foreign
  `ClientInterface`, or this library's `Client` over a foreign `TransportInterface`. On a pipe, a
  socket or `php://input`, `getSize()` is whatever `fstat` reports rather than proof the body is
  empty, so a stream carrying data could report zero, pass the old exemption, and lose that data on
  the second attempt. Requests with no body of their own are unaffected: a PSR-7 message
  constructed without an explicit body gets a seekable `php://temp` stream, so `GET`, `HEAD`,
  `DELETE` and any seekable `POST`/`PUT` still retry exactly as before.
- **`RetryClient` also refuses to retry a request whose per-request options write a body key.**
  Any option — `RawCurlOption` or a third-party `CurlOptionInterface` — whose `toCurlOptions()`
  returns a key of the body family (`CURLOPT_POSTFIELDS`, `CURLOPT_INFILE`, `CURLOPT_INFILESIZE`,
  `CURLOPT_READFUNCTION`, `CURLOPT_UPLOAD`, `CURLOPT_PUT`, `CURLOPT_POST`, `CURLOPT_CUSTOMREQUEST`,
  `CURLOPT_NOBODY`, `CURLOPT_HTTPGET` and their variants) makes what curl sends underivable from
  the PSR-7 message, so the retry throws a `ClientException` naming the request body or its
  options. The case this closes: an empty PSR-7 body plus `RawCurlOption(CURLOPT_READFUNCTION, ...)`
  and `RawCurlOption(CURLOPT_UPLOAD, true)` used to pass the size-based heuristic while libcurl
  streamed a body from the consumer's callback on every attempt. Options outside that family, such
  as `RawCurlOption(CURLOPT_REFERER, ...)`, leave the retry alone.
- **A `HEAD` request never sends a request body.** A body attached to a `HEAD` is discarded
  silently whatever its size, and no `Content-Length` is derived from it. `HEAD` maps to
  `CURLOPT_NOBODY` alone — no upload switch, read callback, post fields or custom request are
  derived from the body. Use a method that carries a payload if you need one on the wire.
- **Requests with non-replayable bodies no longer follow redirects.** Bodies larger than 1 MiB
  and bodies of unknown size are streamed through a read callback that PHP's curl binding cannot
  rewind, so the caller now receives the 3xx response instead of a response from the next hop.
  This also removes the curl errno 65 `NetworkException` previously produced by a 307/308 redirect
  of a streamed body; bodies up to 1 MiB remain replayable and continue to follow redirects. A
  `HEAD` is exempt whatever body it carries: it sends none, so there is nothing to replay and its
  redirects are still followed. This suppression cannot be lifted through `RedirectPolicy`:
  unlike the credentials-based and request-target-based redirect suppressions described
  elsewhere in this file, there is no constructor argument that restores following for a
  streamed body — `RedirectPolicy` has no opt-in for this guard at all. A direct
  `RawCurlOption(CURLOPT_FOLLOWLOCATION, true)` placed after any `RedirectPolicy` in the option
  chain does override it, deliberately, as a low-level escape hatch.
- **Validation is strict on outbound data.** Header names/values, the HTTP
  method, the URI host and multipart part names/filenames reject control
  characters instead of forwarding them to the wire. This now also covers
  response status: `Psr7\Response::withStatus()` and
  `Psr17\ResponseFactory::createResponse()` throw `InvalidArgumentException`
  for a status code outside `100`-`999` or a reason phrase containing `\r`,
  `\n` or `\0` — neither was validated before. A response actually parsed off
  the wire is unaffected: the `Response` constructor and the response parser
  stay tolerant of whatever status code a real server sent.
- **Empty header value lists are normalized, while empty strings are preserved and sent — with
  two exceptions.** `withHeader($name, [])` removes the header, `withAddedHeader($name, [])` is
  a no-op, and `[]` entries in `Request` or `Response` constructor headers are omitted. This
  empty-list behavior deliberately differs from Guzzle and Nyholm, which reject it. An empty
  string, whether passed as `''` or `['']`, remains an actual header value through
  `withHeader()`, `withAddedHeader()`, and constructors. Lists with multiple entries are
  preserved exactly, including `['a', '']` and `['', '']`, and keep their existing joined wire
  representation — except for `Host`, see below. Most headers whose entire value is empty are
  sent on the wire — using curl's semicolon syntax (`Name;`) — instead of being silently
  suppressed by libcurl. In particular,
  `withHeader('Authorization', '')` sends an empty `Authorization` header (`Authorization;`)
  where the request previously arrived without one. Two headers do not follow this rule:
  `Content-Length` and `Transfer-Encoding` are dropped unconditionally regardless of their value
  (see the next bullet), and `Host` must carry exactly one non-empty value or the request throws
  a `RequestException`, because curl derives `Host` framing itself and a blank or multi-valued
  override would replace a correct `Host` on the wire rather than merely being omitted.
- **`Content-Length` and `Transfer-Encoding` set on the PSR-7 message are no longer forwarded to
  curl.** Both are dropped before the request reaches the transport, whatever value they carry.
  libcurl derives message framing itself from the body it is actually given; forwarding a
  caller-supplied value that disagrees with that body let a declared `Content-Length` diverge
  from the real byte count on a reused (keep-alive) connection — a request-smuggling primitive.
  If you set framing headers yourself today, expect them to be silently absent from the outgoing
  request. `RawCurlOption` / `withCurlOption()` remains the way to own framing deliberately. One
  side effect: `Transfer-Encoding` no longer disables automatic redirect following the way it
  used to. It sits outside the credentials safelist described in the README's redirects section,
  but since it is dropped before that safelist check ever sees it, setting it now has no effect
  on whether redirects are followed. `Content-Length` is unaffected by this side effect — it was
  already on the safelist, so dropping it changes nothing there.
- **`withRequestTarget()` is no longer a transport no-op.** A target that differs from the
  origin-form derived from the URI is sent through `CURLOPT_REQUEST_TARGET`, while the connection
  URL remains unchanged. Custom targets reject spaces and control characters at the transport
  boundary even when supplied by an external `RequestInterface`. They require libcurl 7.55.0 or
  newer; on an older libcurl the request fails with a `RequestException` instead of silently using
  the URI-derived target. Pinning a custom target also disables automatic redirect following:
  `CURLOPT_REQUEST_TARGET` is per-handle, not per-hop, so libcurl would otherwise replay the same
  target — original path included — against whatever host a `Location` points to. Pass
  `new RedirectPolicy(followWithPinnedRequestTarget: true)` if you need to keep following after
  pinning a target — this opt-in is independent of `followWithCredentials` and does not also
  restore following when the request carries credentials. Turning both on at once is the most
  dangerous combination the library can reach: a pinned target carrying credentials replays
  unchanged, secret included, against whatever origin the redirect points to.
- **`CurlPromise::then()` composes instead of returning itself.** It now
  returns a new resolved promise carrying the return value of whichever
  callback ran, matching the `php-http/promise` contract. `$promise->then($a)->then($b)`
  used to hand `$b` the original response regardless of what `$a` did; after
  this change it hands `$b` whatever `$a` returned. An `$onRejected` callback that returns
  normally recovers from the rejection; a callback returning a `Promise` is not unwrapped; an
  `\Error` raised while the transfer settles is stored as the rejection reason, while an
  `\Error` thrown by a `then()` callback propagates out of `then()` immediately and only an
  `\Exception` thrown there produces a rejected derived promise.

## What's new (not a 1:1 mapping)

Streaming responses, multipart uploads, retry, compression, HTTP/2+3, and
async/pooled requests are new in 1.0 — the old client never supported them.
See the README's Quick start, "JSON, forms, multipart", "Streaming responses",
"Retry", and "Async and pooling" sections for usage.

`RequestBuilder::downloadTo($path)` and `Streaming\FileSink` stream a response body into a file
through a part file and an atomic rename, restart the file on a `RetryClient` attempt and leave no
partial file behind on a transport failure; 4xx/5xx bodies are stored and reported through the
status, never thrown.

`MaxResponseSize`/`RequestBuilder::withMaxResponseSize()` adds an opt-in response ceiling on both
buffered and streamed transfers. Exceeding it throws `Exceptions\ResponseSizeException`, a
non-network library-policy failure; see the README's "Transfer limits" section for errno and
callback-chunk tolerance details.

`Exceptions\ResponseBufferException`, `Exceptions\ResponseSizeException`, and
`Contract\NonRetryableExceptionInterface` are also new in 1.0, covering local response-buffering
and response-size-policy failures as cases distinct from network failures — see "Error handling
change" above.

<?php declare(strict_types=1);

namespace Domm98CZ\Curl\Transport;

use Domm98CZ\Curl\BodyReplay;
use Domm98CZ\Curl\Contract\CurlOptionsMapperInterface;
use Domm98CZ\Curl\Contract\OptionsInterface;
use Domm98CZ\Curl\Exceptions\RequestException;
use Domm98CZ\Curl\HttpSchemeGuard;
use Domm98CZ\Curl\Options\RedirectPolicy;
use Domm98CZ\Curl\Psr7\HttpGrammar;
use Domm98CZ\Curl\RedirectGuards;
use InvalidArgumentException;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\UriInterface;
use RuntimeException;

final class CurlOptionsMapper implements CurlOptionsMapperInterface
{
    // A body this size or smaller is handed to libcurl as a whole buffer so it can replay it after a
    // 307/308 redirect; PHP's curl binding exposes no CURLOPT_SEEKFUNCTION to rewind a read callback.
    public const REPLAYABLE_BODY_LIMIT = 1_048_576;

    // libcurl derives message framing from the body options alone; a value carried over from the PSR-7
    // message can contradict what is actually sent and turn a keep-alive connection into a smuggling
    // primitive, so it never reaches the wire. RawCurlOption(CURLOPT_HTTPHEADER) remains the escape hatch.
    private const DROPPED_FRAMING_HEADERS = ['content-length', 'transfer-encoding'];

    public function map(RequestInterface $request, OptionsInterface $options): array
    {
        $method = $request->getMethod();
        HttpGrammar::assertMethod($method);

        $uri = $request->getUri();
        $scheme = strtolower($uri->getScheme());

        $curlOptions = [
            CURLOPT_URL => (string) $uri,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CONNECTTIMEOUT => 30,
        ];

        $targetIsPinned = $this->mapRequestTarget($request, $uri, $curlOptions);

        $protocols = $this->protocolMaskFor($scheme);
        if ($protocols !== null) {
            $curlOptions[CURLOPT_PROTOCOLS] = $protocols;
            $curlOptions[CURLOPT_REDIR_PROTOCOLS] = $protocols;
        }

        if (HttpSchemeGuard::isHttp($scheme)) {
            $curlOptions[CURLOPT_ENCODING] = '';
        }

        $headers = $this->mapHeaders($request, $uri);
        $bodyShape = RequestBodyPolicy::methodCarriesRequestBody($method)
            ? $this->mapBody($request, $curlOptions, $headers)
            : BodyShape::None;
        $this->mapMethod($method, $bodyShape, $curlOptions);
        $curlOptions[CURLOPT_HTTPHEADER] = $headers;

        $guards = RedirectGuardDetector::detect($uri, $headers, $bodyShape->isStreamed(), $targetIsPinned);
        $this->applyOptions($curlOptions, $options, $guards);

        return $curlOptions;
    }

    public function replayabilityOf(RequestInterface $request, OptionsInterface $options): BodyReplay
    {
        foreach ($options->all() as $option) {
            // an option writing a body key makes what reaches the wire underivable from the message
            if (RequestBodyPolicy::writesBody($option->toCurlOptions())) {
                return BodyReplay::NotReplayable;
            }
        }

        if (!RequestBodyPolicy::methodCarriesRequestBody($request->getMethod())) {
            return BodyReplay::Replayable;
        }

        $body = $request->getBody();
        // asking for the size and for seekability is the whole inspection: reading or rewinding
        // here would spend a one-shot stream before the transfer that is meant to consume it
        if (!RequestBodyPolicy::shapeForSize($body->getSize())->hasBody()) {
            return BodyReplay::Replayable;
        }

        return $body->isSeekable() ? BodyReplay::Replayable : BodyReplay::NotReplayable;
    }

    /** @param array<int, mixed> $curlOptions */
    private function applyOptions(array &$curlOptions, OptionsInterface $options, RedirectGuards $guards): void
    {
        // Options apply in insertion order and a later write to the same key wins, with no exception:
        // the default policy is applied first like any other, and a RedirectPolicy only differs in
        // that the mapper, as the sole holder of the guards, writes CURLOPT_FOLLOWLOCATION for it.
        foreach ([new RedirectPolicy(), ...$options->all()] as $option) {
            foreach ($option->toCurlOptions() as $key => $value) {
                $curlOptions[$key] = $value;
            }
            if ($option instanceof RedirectPolicy) {
                $curlOptions[CURLOPT_FOLLOWLOCATION] = $option->allowsFollowing($guards);
            }
        }
    }

    /** @param array<int, mixed> $curlOptions */
    private function mapRequestTarget(RequestInterface $request, UriInterface $uri, array &$curlOptions): bool
    {
        $requestTarget = $request->getRequestTarget();
        // validated before the origin-form comparison so a foreign UriInterface cannot smuggle control
        // characters into CURLOPT_URL through the branch that maps no target at all
        $this->assertValidRequestTarget($requestTarget, $request);

        if ($requestTarget === $this->originFormFromUri($uri)) {
            return false;
        }

        if (!defined('CURLOPT_REQUEST_TARGET')) {
            throw new RequestException(
                'Custom request targets require CURLOPT_REQUEST_TARGET (libcurl 7.55.0 or newer).',
                $request
            );
        }

        $requestTargetOption = constant('CURLOPT_REQUEST_TARGET');
        // PHPStan resolves the constant from the current extension, while this guard protects runtime variants.
        // @phpstan-ignore function.alreadyNarrowedType
        if (!is_int($requestTargetOption)) {
            throw new RequestException('CURLOPT_REQUEST_TARGET is not a valid curl option.', $request);
        }

        // CURLOPT_REQUEST_TARGET is per-handle, not per-hop: libcurl replays a pinned target against the
        // redirect target's origin and discards the path from Location, leaking it to an unintended host.
        $curlOptions[$requestTargetOption] = $requestTarget;
        return true;
    }

    private function originFormFromUri(UriInterface $uri): string
    {
        $originForm = $uri->getPath();
        if ($originForm === '') {
            $originForm = '/';
        }
        if ($uri->getQuery() !== '') {
            $originForm .= '?' . $uri->getQuery();
        }
        return $originForm;
    }

    private function assertValidRequestTarget(string $requestTarget, RequestInterface $request): void
    {
        try {
            HttpGrammar::assertRequestTarget($requestTarget);
        } catch (InvalidArgumentException $exception) {
            throw new RequestException($exception->getMessage(), $request, $exception);
        }
    }

    /**
     * @param array<int, mixed> $curlOptions
     * @param string[] $headers
     */
    private function mapBody(RequestInterface $request, array &$curlOptions, array &$headers): BodyShape
    {
        try {
            $body = $request->getBody();
            $size = $body->getSize();
            $shape = RequestBodyPolicy::shapeForSize($size);
            if (!$shape->hasBody()) {
                return $shape;
            }

            if ($body->isSeekable()) {
                $body->rewind();
            }

            if ($shape === BodyShape::Buffered) {
                $curlOptions[CURLOPT_POSTFIELDS] = $body->getContents();
                if (!$request->hasHeader('Content-Type')) {
                    // an empty value tells libcurl to omit its own application/x-www-form-urlencoded default
                    $headers[] = 'Content-Type:';
                }
                if (!$request->hasHeader('Expect')) {
                    // a whole buffer needs no interim response; libcurl's own Expect threshold varies by
                    // version and a server that never answers 100 Continue would cost a full wait per request
                    $headers[] = 'Expect:';
                }
                return $shape;
            }

            if ($size !== null) {
                $curlOptions[CURLOPT_INFILESIZE] = $size;
            }
            $curlOptions[CURLOPT_READFUNCTION] = static fn ($ch, $fd, int $length): string
                => $body->eof() ? '' : $body->read($length);
            return $shape;
        } catch (RequestException $exception) {
            throw $exception;
        } catch (RuntimeException $exception) {
            throw new RequestException('Unable to prepare the request body.', $request, $exception);
        }
    }

    /** @param array<int, mixed> $curlOptions */
    private function mapMethod(string $method, BodyShape $bodyShape, array &$curlOptions): void
    {
        if ($method === 'GET') {
            if (!$bodyShape->hasBody()) {
                $curlOptions[CURLOPT_HTTPGET] = true;
                return;
            }
            if ($bodyShape->isStreamed()) {
                $curlOptions[CURLOPT_UPLOAD] = true;
            }
            $curlOptions[CURLOPT_CUSTOMREQUEST] = 'GET';
            return;
        }
        if ($method === 'HEAD') {
            // CURLOPT_NOBODY alone already puts HEAD on the wire; a CUSTOMREQUEST would only duplicate it.
            $curlOptions[CURLOPT_NOBODY] = true;
            return;
        }
        if ($method === 'POST') {
            if (!$bodyShape->isStreamed()) {
                $curlOptions[CURLOPT_POST] = true;
                return;
            }

            $curlOptions[CURLOPT_UPLOAD] = true;
            $curlOptions[CURLOPT_CUSTOMREQUEST] = 'POST';
            return;
        }

        if ($bodyShape->isStreamed()) {
            $curlOptions[CURLOPT_UPLOAD] = true;
        }
        $curlOptions[CURLOPT_CUSTOMREQUEST] = $method;
    }

    private function protocolMaskFor(string $scheme): ?int
    {
        if ($scheme === 'https') {
            return CURLPROTO_HTTPS;
        }
        if ($scheme === '' || $scheme === 'http') {
            return CURLPROTO_HTTP | CURLPROTO_HTTPS;
        }
        return self::protocolByScheme()[$scheme] ?? null;
    }

    /** @return array<string, int> */
    private static function protocolByScheme(): array
    {
        /** @var array<string, int>|null $protocols */
        static $protocols = null;
        if ($protocols !== null) {
            return $protocols;
        }

        $protocols = [];
        foreach ([
            'dict' => 'CURLPROTO_DICT',
            'file' => 'CURLPROTO_FILE',
            'ftp' => 'CURLPROTO_FTP',
            'ftps' => 'CURLPROTO_FTPS',
            'gopher' => 'CURLPROTO_GOPHER',
            'imap' => 'CURLPROTO_IMAP',
            'imaps' => 'CURLPROTO_IMAPS',
            'ldap' => 'CURLPROTO_LDAP',
            'ldaps' => 'CURLPROTO_LDAPS',
            'mqtt' => 'CURLPROTO_MQTT',
            'mqtts' => 'CURLPROTO_MQTTS',
            'pop3' => 'CURLPROTO_POP3',
            'pop3s' => 'CURLPROTO_POP3S',
            'rtmp' => 'CURLPROTO_RTMP',
            'rtmpe' => 'CURLPROTO_RTMPE',
            'rtmps' => 'CURLPROTO_RTMPS',
            'rtmpt' => 'CURLPROTO_RTMPT',
            'rtmpte' => 'CURLPROTO_RTMPTE',
            'rtmpts' => 'CURLPROTO_RTMPTS',
            'rtsp' => 'CURLPROTO_RTSP',
            'scp' => 'CURLPROTO_SCP',
            'sftp' => 'CURLPROTO_SFTP',
            'smb' => 'CURLPROTO_SMB',
            'smbs' => 'CURLPROTO_SMBS',
            'smtp' => 'CURLPROTO_SMTP',
            'smtps' => 'CURLPROTO_SMTPS',
            'telnet' => 'CURLPROTO_TELNET',
            'tftp' => 'CURLPROTO_TFTP',
        ] as $scheme => $constant) {
            if (!defined($constant)) {
                continue;
            }
            $protocols[$scheme] = constant($constant);
        }

        return $protocols;
    }

    /** @return string[] */
    private function mapHeaders(RequestInterface $request, UriInterface $uri): array
    {
        $derivedHost = $this->hostFromUri($uri);

        $lines = [];
        foreach ($request->getHeaders() as $name => $values) {
            HttpGrammar::assertHeaderName($name);
            // dropped before the value is validated: a header that never reaches the wire cannot frame
            // or inject anything, so rejecting the request over its value would serve no purpose
            if (in_array(strtolower($name), self::DROPPED_FRAMING_HEADERS, true)) {
                continue;
            }
            // an empty value list means the header does not exist, which implode() would flatten into the
            // same empty string as a present-but-empty value and turn into a header on the wire
            if ($values === []) {
                continue;
            }

            $value = $this->headerValue($name, $values, $request);

            // libcurl derives an identical Host itself and rewrites it when a redirect crosses hosts;
            // pinning it here would leak the original host to the redirect target.
            if (strcasecmp($name, 'Host') === 0 && $value === $derivedHost) {
                continue;
            }

            $lines[] = $value === '' ? $name . ';' : sprintf('%s: %s', $name, $value);
        }
        return $lines;
    }

    /** @param string[] $values */
    private function headerValue(string $name, array $values, RequestInterface $request): string
    {
        $value = implode(', ', $values);
        HttpGrammar::assertHeaderValue($value);

        // Host is single-valued framing libcurl would otherwise derive; a blank or comma-joined value
        // would replace a correct Host on the wire instead of merely dropping a preference
        if (strcasecmp($name, 'Host') === 0 && (count($values) !== 1 || trim($values[0]) === '')) {
            throw new RequestException(sprintf(
                'Header "%s" frames the message and must carry exactly one non-empty value; '
                    . 'use RawCurlOption to override libcurl framing deliberately.',
                $name
            ), $request);
        }

        return $value;
    }

    private function hostFromUri(UriInterface $uri): string
    {
        $host = $uri->getHost();
        if ($host === '') {
            return '';
        }
        return $uri->getPort() !== null ? $host . ':' . $uri->getPort() : $host;
    }
}

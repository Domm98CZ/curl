<?php declare(strict_types=1);

namespace Domm98CZ\Curl;

use Domm98CZ\Curl\Async\CurlPromise;
use Domm98CZ\Curl\Contract\AsyncClientInterface;
use Domm98CZ\Curl\Contract\MultiTransportInterface;
use Domm98CZ\Curl\Contract\OptionsInterface;
use Domm98CZ\Curl\Contract\ResponseParserInterface;
use Domm98CZ\Curl\Exceptions\ClientException;
use Domm98CZ\Curl\Transport\CurlMultiTransport;
use Domm98CZ\Curl\Transport\HttpResponseParser;
use Http\Promise\Promise;
use Psr\Http\Message\RequestInterface;

final class AsyncClient implements AsyncClientInterface
{
    public function __construct(
        private readonly MultiTransportInterface $multiTransport = new CurlMultiTransport(),
        private readonly ResponseParserInterface $responseParser = new HttpResponseParser(),
    ) {
    }

    public function sendAsync(RequestInterface $request, ?OptionsInterface $options = null): Promise
    {
        HttpSchemeGuard::assert($request);

        $id = $this->multiTransport->add($request, $options ?? new RequestOptions());

        return new CurlPromise(
            tick: fn () => $this->multiTransport->tick(),
            isDone: fn () => $this->multiTransport->isDone($id),
            resolve: function () use ($id, $request) {
                $raw = $this->multiTransport->takeResult($id);
                if ($raw->isTransportError()) {
                    throw ClientException::forTransportError($raw, $request);
                }
                return $this->responseParser->parse($raw);
            },
            release: fn () => $this->multiTransport->release($id),
        );
    }
}

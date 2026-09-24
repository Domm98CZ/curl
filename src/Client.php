<?php declare(strict_types=1);

namespace Domm98CZ\Curl;

use Domm98CZ\Curl\Contract\HttpClientInterface;
use Domm98CZ\Curl\Contract\OptionsInterface;
use Domm98CZ\Curl\Contract\ReplayAwareInterface;
use Domm98CZ\Curl\Contract\ResponseParserInterface;
use Domm98CZ\Curl\Contract\TransportInterface;
use Domm98CZ\Curl\Exceptions\ClientException;
use Domm98CZ\Curl\Transport\CurlTransport;
use Domm98CZ\Curl\Transport\HttpResponseParser;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

final class Client implements HttpClientInterface, ReplayAwareInterface
{
    public function __construct(
        private readonly TransportInterface $transport = new CurlTransport(),
        private readonly ResponseParserInterface $responseParser = new HttpResponseParser(),
    ) {
    }

    public function replayabilityOf(RequestInterface $request, ?OptionsInterface $options = null): BodyReplay
    {
        return $this->transport->replayabilityOf($request, $options ?? new RequestOptions());
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        return $this->send($request);
    }

    public function send(RequestInterface $request, ?OptionsInterface $options = null): ResponseInterface
    {
        HttpSchemeGuard::assert($request);

        $raw = $this->transport->execute($request, $options ?? new RequestOptions());

        if ($raw->isTransportError()) {
            throw ClientException::forTransportError($raw, $request);
        }

        return $this->responseParser->parse($raw);
    }
}

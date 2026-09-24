<?php declare(strict_types=1);

namespace Domm98CZ\Curl\Tests\Fakes;

use Domm98CZ\Curl\BodyReplay;
use Domm98CZ\Curl\Contract\HttpClientInterface;
use Domm98CZ\Curl\Contract\OptionsInterface;
use Domm98CZ\Curl\Contract\ReplayAwareInterface;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;

final class FakeReplayAwareClient implements HttpClientInterface, ReplayAwareInterface
{
    /** @var list<ResponseInterface|ClientExceptionInterface> */
    private array $queue;

    /** @var list<array{request: RequestInterface, options: ?OptionsInterface}> */
    public array $calls = [];

    /** @var list<array{request: RequestInterface, options: ?OptionsInterface}> */
    public array $verdictQueries = [];

    public function __construct(
        private readonly BodyReplay $verdict,
        ResponseInterface|ClientExceptionInterface ...$queue,
    ) {
        $this->queue = array_values($queue);
    }

    public function replayabilityOf(RequestInterface $request, ?OptionsInterface $options = null): BodyReplay
    {
        $this->verdictQueries[] = ['request' => $request, 'options' => $options];

        return $this->verdict;
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        return $this->send($request);
    }

    public function send(RequestInterface $request, ?OptionsInterface $options = null): ResponseInterface
    {
        $this->calls[] = ['request' => $request, 'options' => $options];

        if ($this->queue === []) {
            throw new RuntimeException('FakeReplayAwareClient queue exhausted — queue more responses.');
        }

        $next = array_shift($this->queue);
        if ($next instanceof ResponseInterface) {
            return $next;
        }

        throw $next;
    }
}

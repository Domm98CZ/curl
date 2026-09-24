<?php declare(strict_types=1);

namespace Domm98CZ\Curl\Tests\Fakes;

use Domm98CZ\Curl\BodyReplay;
use Domm98CZ\Curl\Contract\OptionsInterface;
use Domm98CZ\Curl\Contract\TransportInterface;
use Domm98CZ\Curl\Psr7\Stream;
use Domm98CZ\Curl\RawResponse;
use Domm98CZ\Curl\Transport\CurlOptionsMapper;
use Psr\Http\Message\RequestInterface;
use RuntimeException;

// Stands in for CurlTransport, so it answers replayability the same way CurlTransport does; a
// transport that abstains here would exercise the fallback instead of the path under test.
final class FakeTransport implements TransportInterface
{
    /** @var RawResponse[] */
    private array $queue;

    /** @var array<int, array{request: RequestInterface, options: OptionsInterface}> */
    public array $calls = [];

    public function __construct(RawResponse ...$responses)
    {
        $this->queue = $responses;
    }

    public function replayabilityOf(RequestInterface $request, OptionsInterface $options): BodyReplay
    {
        return (new CurlOptionsMapper())->replayabilityOf($request, $options);
    }

    public function execute(RequestInterface $request, OptionsInterface $options): RawResponse
    {
        $this->calls[] = ['request' => $request, 'options' => $options];

        if ($this->queue === []) {
            throw new RuntimeException('FakeTransport queue exhausted — queue more RawResponse values.');
        }

        $response = array_shift($this->queue);
        $streamHandler = $options->getStreamHandler();
        if ($streamHandler !== null) {
            foreach (preg_split('/(?<=\n)/', $response->headerRaw, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $line) {
                $streamHandler->onHeaderLine($line);
            }
            $streamHandler->onChunk((string) $response->body);
            // like the real transports, a handled body is not buffered and the response carries none
            $response = new RawResponse(
                $response->errno,
                $response->error,
                $response->headerRaw,
                new Stream(fopen('php://temp', 'r+')),
                $response->info,
                false,
                $response->responseSizeExceeded,
            );
        }
        $options->getTransferInfoCollector()?->collect($response->info);

        return $response;
    }
}

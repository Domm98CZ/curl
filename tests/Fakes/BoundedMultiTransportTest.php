<?php declare(strict_types=1);

namespace Domm98CZ\Curl\Tests\Fakes;

use Domm98CZ\Curl\Contract\MultiTransportInterface;
use Domm98CZ\Curl\Contract\OptionsInterface;
use Domm98CZ\Curl\Psr7\Request;
use Domm98CZ\Curl\RawResponse;
use Domm98CZ\Curl\RequestOptions;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use RuntimeException;

final class BoundedMultiTransportTest extends TestCase
{
    public function testCompletedSiblingsCannotResetTheLimitForAStuckHandle(): void
    {
        $inner = new class implements MultiTransportInterface {
            /** @var array<int, bool> */
            private array $done = [];
            private int $nextId = 1;

            public function add(RequestInterface $request, OptionsInterface $options): int
            {
                $id = $this->nextId++;
                $this->done[$id] = false;

                return $id;
            }

            public function tick(): void
            {
                foreach ($this->done as $id => $_) {
                    if ($id !== 1) {
                        $this->done[$id] = true;
                    }
                }
            }

            public function isDone(int $id): bool
            {
                return $this->done[$id] ?? throw new RuntimeException('Unknown handle.');
            }

            public function takeResult(int $id): RawResponse
            {
                throw new RuntimeException('No result is needed by this test.');
            }

            public function release(int $id): void
            {
                unset($this->done[$id]);
            }
        };
        $transport = new BoundedMultiTransport($inner, maxTickCalls: 3, deadlineSeconds: 1.0);
        $stuck = $transport->add(new Request('GET', '/stuck'), new RequestOptions());
        $completedA = $transport->add(new Request('GET', '/completed-a'), new RequestOptions());
        $completedB = $transport->add(new Request('GET', '/completed-b'), new RequestOptions());
        $transport->tick();

        self::assertTrue($transport->isDone($completedA));
        self::assertTrue($transport->isDone($completedB));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('tick limit');
        while (!$transport->isDone($stuck)) {
            self::assertTrue($transport->isDone($completedA));
            self::assertTrue($transport->isDone($completedB));
            $transport->tick();
        }
    }
}

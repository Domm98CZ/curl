<?php declare(strict_types=1);

namespace Domm98CZ\Curl\Tests\Fakes;

use Domm98CZ\Curl\Psr7\Request;
use Domm98CZ\Curl\Psr7\Stream;
use Domm98CZ\Curl\RawResponse;
use Domm98CZ\Curl\RequestOptions;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class FakeMultiTransportTest extends TestCase
{
    public function testAddRejectsAnEmptyResponseQueue(): void
    {
        $transport = new FakeMultiTransport();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('without a queued response');
        $transport->add(new Request('GET', '/'), new RequestOptions());
    }

    public function testIsDoneRejectsAnUnknownHandle(): void
    {
        $transport = new FakeMultiTransport();

        $this->expectException(RuntimeException::class);
        $transport->isDone(42);
    }

    public function testIsDoneRejectsAConsumedHandle(): void
    {
        $raw = new RawResponse(0, '', "HTTP/1.1 200 OK\r\n\r\n", new Stream(fopen('php://temp', 'r+')), []);
        $transport = new FakeMultiTransport($raw);
        $id = $transport->add(new Request('GET', '/'), new RequestOptions());
        $transport->tick();
        $transport->takeResult($id);

        $this->expectException(RuntimeException::class);
        $transport->isDone($id);
    }
}

<?php declare(strict_types=1);

namespace Domm98CZ\Curl\Tests;

use Domm98CZ\Curl\TransferInfoCollector;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientExceptionInterface;

final class TransferInfoCollectorTest extends TestCase
{
    public function testGetBeforeCollectThrows(): void
    {
        $this->expectException(ClientExceptionInterface::class);
        (new TransferInfoCollector())->get();
    }

    public function testCollectThenGetReturnsPopulatedTransferInfo(): void
    {
        $collector = new TransferInfoCollector();
        $collector->collect(['total_time' => 0.25, 'namelookup_time' => 0.01, 'connect_time' => 0.02, 'size_upload' => 10, 'size_download' => 20, 'primary_ip' => '1.2.3.4']);

        $info = $collector->get();

        self::assertSame(250.0, $info->totalTimeMs);
        self::assertSame('1.2.3.4', $info->primaryIp);
    }

    public function testCollectCanBeCalledOnlyOncePerInstance(): void
    {
        $collector = new TransferInfoCollector();
        $collector->collect(['total_time' => 0.1]);

        $this->expectException(ClientExceptionInterface::class);
        $collector->collect(['total_time' => 0.2]);
    }

    public function testResetForRetryDiscardsThePreviousResult(): void
    {
        $collector = new TransferInfoCollector();
        $collector->collect(['total_time' => 0.1]);

        self::assertTrue($collector->resetForRetry());
        $collector->collect(['total_time' => 0.2]);

        self::assertSame(200.0, $collector->get()->totalTimeMs);
    }
}

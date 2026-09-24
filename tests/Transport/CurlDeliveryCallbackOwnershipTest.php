<?php declare(strict_types=1);

namespace Domm98CZ\Curl\Tests\Transport;

use Domm98CZ\Curl\AsyncClient;
use Domm98CZ\Curl\Client;
use Domm98CZ\Curl\Exceptions\RequestException;
use Domm98CZ\Curl\RequestBuilder;
use Domm98CZ\Curl\Transport\CurlMultiTransport;
use Domm98CZ\Curl\Transport\CurlTransport;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CurlDeliveryCallbackOwnershipTest extends TestCase
{
    #[DataProvider('deliveryCallbackProvider')]
    public function testDeliveryCallbackIsRejectedInEveryTransportPath(string $path, int $option): void
    {
        $builder = RequestBuilder::get('http://example.com/')
            ->withCurlOption($option, static fn (): int => 0);

        try {
            if ($path === 'sync') {
                $builder->send(new Client(new CurlTransport()));
            } elseif ($path === 'raw') {
                $builder->sendRaw(new CurlTransport());
            } else {
                $builder->sendAsync(new AsyncClient(new CurlMultiTransport()));
            }
            self::fail(sprintf('Expected callback option %d to be rejected in the %s path.', $option, $path));
        } catch (RequestException $exception) {
            self::assertStringContainsString('owned by the library', $exception->getMessage());
            self::assertStringContainsString('StreamHandler', $exception->getMessage());
        }
    }

    /** @return iterable<string, array{string, int}> */
    public static function deliveryCallbackProvider(): iterable
    {
        foreach ([CURLOPT_WRITEFUNCTION, CURLOPT_HEADERFUNCTION] as $option) {
            yield sprintf('sync option %d', $option) => ['sync', $option];
            yield sprintf('raw option %d', $option) => ['raw', $option];
            yield sprintf('async option %d', $option) => ['async', $option];
        }
    }
}

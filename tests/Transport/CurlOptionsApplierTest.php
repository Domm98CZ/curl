<?php declare(strict_types=1);

namespace Domm98CZ\Curl\Tests\Transport;

use CurlHandle;
use Domm98CZ\Curl\Exceptions\RequestException;
use Domm98CZ\Curl\Psr7\Request;
use Domm98CZ\Curl\Transport\CurlOptionsApplier;
use PHPUnit\Framework\TestCase;

final class CurlOptionsApplierTest extends TestCase
{
    public function testCallbackTypeErrorIsClassifiedAsARequestException(): void
    {
        $handle = curl_init();
        self::assertInstanceOf(CurlHandle::class, $handle);
        $request = new Request('GET', 'http://example.com/');

        try {
            CurlOptionsApplier::apply($handle, [CURLOPT_WRITEFUNCTION => 'not callable'], $request);
            self::fail('Expected PHP to reject the callback option.');
        } catch (RequestException $exception) {
            self::assertSame($request, $exception->getRequest());
            self::assertInstanceOf(\TypeError::class, $exception->getPrevious());
            self::assertSame(
                sprintf('PHP rejected option %d before libcurl received it; the request was not sent.', CURLOPT_WRITEFUNCTION),
                $exception->getMessage(),
            );
        }
    }

    public function testValueErrorKeepsTheLibcurlRejectionClassification(): void
    {
        $handle = curl_init();
        self::assertInstanceOf(CurlHandle::class, $handle);
        $request = new Request('GET', 'http://example.com/');
        $option = 999_999_999;

        try {
            CurlOptionsApplier::apply($handle, [$option => true], $request);
            self::fail('Expected PHP to reject the unknown cURL option.');
        } catch (RequestException $exception) {
            self::assertSame($request, $exception->getRequest());
            self::assertInstanceOf(\ValueError::class, $exception->getPrevious());
            self::assertSame(
                sprintf('libcurl rejected option %d; the request was not sent.', $option),
                $exception->getMessage(),
            );
        }
    }
}

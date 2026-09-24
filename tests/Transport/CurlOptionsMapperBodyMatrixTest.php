<?php declare(strict_types=1);

namespace Domm98CZ\Curl\Tests\Transport;

use Domm98CZ\Curl\Psr7\Request;
use Domm98CZ\Curl\Psr7\Stream;
use Domm98CZ\Curl\RequestOptions;
use Domm98CZ\Curl\Tests\Fakes\UnknownSizeStream;
use Domm98CZ\Curl\Transport\CurlOptionsMapper;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\StreamInterface;

/**
 * Pins the whole option array, keys in insertion order, for every method x body shape pair.
 * CurlOptionsApplier feeds libcurl one option at a time, so the order is part of the wire
 * contract, not an implementation detail.
 */
final class CurlOptionsMapperBodyMatrixTest extends TestCase
{
    private const PAYLOAD = '@payload';
    private const READ_FUNCTION = '@readfunction';

    private const SMALL = 'payload';

    /**
     * @param array<int, mixed> $bodyAndMethodOptions
     * @param array<string, string> $requestHeaders
     * @param string[] $expectedHeaders
     */
    #[DataProvider('bodyMatrixProvider')]
    public function testTheCompleteOptionArrayForEveryMethodAndBodyShape(
        string $method,
        string $bodyKind,
        bool $followsRedirects,
        array $bodyAndMethodOptions,
        array $requestHeaders,
        array $expectedHeaders
    ): void {
        $payload = self::payloadFor($bodyKind);
        $request = (new Request($method, '/', $requestHeaders))->withBody(self::bodyFor($bodyKind, $payload));

        $actual = (new CurlOptionsMapper())->map($request, new RequestOptions());

        $expected = [
            CURLOPT_URL => '/',
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CONNECTTIMEOUT => 30,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_ENCODING => '',
        ];
        foreach ($bodyAndMethodOptions as $option => $value) {
            $expected[$option] = $value === self::PAYLOAD ? $payload : $value;
        }
        $expected[CURLOPT_HTTPHEADER] = $expectedHeaders;
        // the redirect keys come from the implicit default RedirectPolicy, applied after the request mapping
        $expected[CURLOPT_MAXREDIRS] = 20;
        $expected[CURLOPT_FOLLOWLOCATION] = $followsRedirects;

        if (array_key_exists(CURLOPT_READFUNCTION, $actual)) {
            $reader = $actual[CURLOPT_READFUNCTION];
            self::assertIsCallable($reader);
            self::assertSame($payload, $reader(null, null, strlen($payload) + 1));
            $actual[CURLOPT_READFUNCTION] = self::READ_FUNCTION;
        }

        self::assertSame($expected, $actual);
    }

    /**
     * @return iterable<string, array{string, string, bool, array<int, mixed>, array<string, string>, string[]}>
     */
    public static function bodyMatrixProvider(): iterable
    {
        yield 'GET without a body' => [
            'GET', 'empty', true,
            [CURLOPT_HTTPGET => true],
            [], [],
        ];
        yield 'GET with a small buffered body' => [
            'GET', 'small', true,
            [CURLOPT_POSTFIELDS => self::PAYLOAD, CURLOPT_CUSTOMREQUEST => 'GET'],
            [], ['Content-Type:', 'Expect:'],
        ];
        yield 'GET with a body exactly at the replayable limit' => [
            'GET', 'at-limit', true,
            [CURLOPT_POSTFIELDS => self::PAYLOAD, CURLOPT_CUSTOMREQUEST => 'GET'],
            [], ['Content-Type:', 'Expect:'],
        ];
        yield 'GET with a body one byte over the replayable limit' => [
            'GET', 'over-limit', false,
            [
                CURLOPT_INFILESIZE => CurlOptionsMapper::REPLAYABLE_BODY_LIMIT + 1,
                CURLOPT_READFUNCTION => self::READ_FUNCTION,
                CURLOPT_UPLOAD => true,
                CURLOPT_CUSTOMREQUEST => 'GET',
            ],
            [], [],
        ];
        yield 'GET with a body of unknown size' => [
            'GET', 'unknown', false,
            [
                CURLOPT_READFUNCTION => self::READ_FUNCTION,
                CURLOPT_UPLOAD => true,
                CURLOPT_CUSTOMREQUEST => 'GET',
            ],
            [], [],
        ];

        yield 'POST without a body' => [
            'POST', 'empty', true,
            [CURLOPT_POST => true],
            [], [],
        ];
        yield 'POST with a small buffered body' => [
            'POST', 'small', true,
            [CURLOPT_POSTFIELDS => self::PAYLOAD, CURLOPT_POST => true],
            [], ['Content-Type:', 'Expect:'],
        ];
        yield 'POST with a body exactly at the replayable limit' => [
            'POST', 'at-limit', true,
            [CURLOPT_POSTFIELDS => self::PAYLOAD, CURLOPT_POST => true],
            [], ['Content-Type:', 'Expect:'],
        ];
        yield 'POST with a body one byte over the replayable limit' => [
            'POST', 'over-limit', false,
            [
                CURLOPT_INFILESIZE => CurlOptionsMapper::REPLAYABLE_BODY_LIMIT + 1,
                CURLOPT_READFUNCTION => self::READ_FUNCTION,
                CURLOPT_UPLOAD => true,
                CURLOPT_CUSTOMREQUEST => 'POST',
            ],
            [], [],
        ];
        yield 'POST with a body of unknown size' => [
            'POST', 'unknown', false,
            [
                CURLOPT_READFUNCTION => self::READ_FUNCTION,
                CURLOPT_UPLOAD => true,
                CURLOPT_CUSTOMREQUEST => 'POST',
            ],
            [], [],
        ];

        yield 'PATCH without a body' => [
            'PATCH', 'empty', true,
            [CURLOPT_CUSTOMREQUEST => 'PATCH'],
            [], [],
        ];
        yield 'PATCH with a small buffered body' => [
            'PATCH', 'small', true,
            [CURLOPT_POSTFIELDS => self::PAYLOAD, CURLOPT_CUSTOMREQUEST => 'PATCH'],
            [], ['Content-Type:', 'Expect:'],
        ];
        yield 'PATCH with a body exactly at the replayable limit' => [
            'PATCH', 'at-limit', true,
            [CURLOPT_POSTFIELDS => self::PAYLOAD, CURLOPT_CUSTOMREQUEST => 'PATCH'],
            [], ['Content-Type:', 'Expect:'],
        ];
        yield 'PATCH with a body one byte over the replayable limit' => [
            'PATCH', 'over-limit', false,
            [
                CURLOPT_INFILESIZE => CurlOptionsMapper::REPLAYABLE_BODY_LIMIT + 1,
                CURLOPT_READFUNCTION => self::READ_FUNCTION,
                CURLOPT_UPLOAD => true,
                CURLOPT_CUSTOMREQUEST => 'PATCH',
            ],
            [], [],
        ];
        yield 'PATCH with a body of unknown size' => [
            'PATCH', 'unknown', false,
            [
                CURLOPT_READFUNCTION => self::READ_FUNCTION,
                CURLOPT_UPLOAD => true,
                CURLOPT_CUSTOMREQUEST => 'PATCH',
            ],
            [], [],
        ];

        foreach (['empty', 'small', 'at-limit', 'over-limit', 'unknown'] as $bodyKind) {
            yield sprintf('HEAD with a %s body', $bodyKind) => [
                'HEAD', $bodyKind, true,
                [CURLOPT_NOBODY => true],
                [], [],
            ];
        }

        yield 'an explicit Content-Type is not replaced on a buffered body' => [
            'POST', 'small', true,
            [CURLOPT_POSTFIELDS => self::PAYLOAD, CURLOPT_POST => true],
            ['Content-Type' => 'application/json'],
            ['Content-Type: application/json', 'Expect:'],
        ];
        yield 'an explicit Expect is forwarded instead of being removed on a buffered body' => [
            'POST', 'small', true,
            [CURLOPT_POSTFIELDS => self::PAYLOAD, CURLOPT_POST => true],
            ['Expect' => '100-continue'],
            ['Expect: 100-continue', 'Content-Type:'],
        ];
        yield 'an explicit Content-Type adds nothing to a streamed body' => [
            'POST', 'over-limit', false,
            [
                CURLOPT_INFILESIZE => CurlOptionsMapper::REPLAYABLE_BODY_LIMIT + 1,
                CURLOPT_READFUNCTION => self::READ_FUNCTION,
                CURLOPT_UPLOAD => true,
                CURLOPT_CUSTOMREQUEST => 'POST',
            ],
            ['Content-Type' => 'application/json'],
            ['Content-Type: application/json'],
        ];
    }

    private static function payloadFor(string $bodyKind): string
    {
        return match ($bodyKind) {
            'empty' => '',
            'small', 'unknown' => self::SMALL,
            'at-limit' => str_repeat('a', CurlOptionsMapper::REPLAYABLE_BODY_LIMIT),
            'over-limit' => str_repeat('a', CurlOptionsMapper::REPLAYABLE_BODY_LIMIT + 1),
            default => self::fail(sprintf('Unknown body kind "%s".', $bodyKind)),
        };
    }

    private static function bodyFor(string $bodyKind, string $payload): StreamInterface
    {
        $resource = fopen('php://temp', 'r+');
        self::assertIsResource($resource);
        fwrite($resource, $payload);
        rewind($resource);
        $stream = new Stream($resource);

        return $bodyKind === 'unknown' ? new UnknownSizeStream($stream) : $stream;
    }
}

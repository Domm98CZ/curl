<?php declare(strict_types=1);

namespace Domm98CZ\Curl\Tests\Psr7;

use Domm98CZ\Curl\Psr7\Response;
use Domm98CZ\Curl\Psr7\Stream;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ResponseTest extends TestCase
{
    public function testDefaultsToStatus200(): void
    {
        $response = new Response();
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('OK', $response->getReasonPhrase());
    }

    public function testConstructorSetsStatusHeadersBody(): void
    {
        $body = new Stream(fopen('php://temp', 'r+'));
        $response = new Response(404, ['Content-Type' => 'text/plain'], $body);

        self::assertSame(404, $response->getStatusCode());
        self::assertSame('Not Found', $response->getReasonPhrase());
        self::assertSame(['text/plain'], $response->getHeader('Content-Type'));
        self::assertSame($body, $response->getBody());
    }

    public function testWithStatusChangesCodeAndDefaultReasonPhrase(): void
    {
        $response = (new Response())->withStatus(500);
        self::assertSame(500, $response->getStatusCode());
        self::assertSame('Internal Server Error', $response->getReasonPhrase());
    }

    public function testUnknownStatusCodeWithoutExplicitReasonPhraseIsEmpty(): void
    {
        $response = (new Response())->withStatus(499);
        self::assertSame('', $response->getReasonPhrase());
    }

    #[DataProvider('invalidStatusCodeProvider')]
    public function testWithStatusRejectsACodeOutsideThreeDigits(int $code): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new Response())->withStatus($code);
    }

    /** @return iterable<string, array{int}> */
    public static function invalidStatusCodeProvider(): iterable
    {
        yield 'zero' => [0];
        yield 'negative' => [-1];
        yield 'below the first class' => [99];
        yield 'four digits' => [1000];
        yield 'typo' => [4040];
        yield 'far out of range' => [99999];
    }

    public function testWithStatusAcceptsANonStandardCodeTheWireCanCarry(): void
    {
        $response = (new Response())->withStatus(999);

        self::assertSame(999, $response->getStatusCode());
        self::assertSame('', $response->getReasonPhrase());
    }

    #[DataProvider('forbiddenReasonPhraseByteProvider')]
    public function testWithStatusRejectsAReasonPhraseCarryingAForbiddenByte(string $phrase): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new Response())->withStatus(200, $phrase);
    }

    /** @return iterable<string, array{string}> */
    public static function forbiddenReasonPhraseByteProvider(): iterable
    {
        yield 'crlf' => ["OK\r\nX-Injected: 1"];
        yield 'lf' => ["OK\nX-Injected: 1"];
        yield 'nul' => ["OK\0"];
    }

    public function testTheConstructorStaysTolerantOfANonStandardCode(): void
    {
        $response = new Response(999, [], null, '1.1', 'Request Denied');

        self::assertSame(999, $response->getStatusCode());
        self::assertSame('Request Denied', $response->getReasonPhrase());
    }
}

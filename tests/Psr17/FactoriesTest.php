<?php declare(strict_types=1);

namespace Domm98CZ\Curl\Tests\Psr17;

use Domm98CZ\Curl\Psr17\ResponseFactory;
use Domm98CZ\Curl\Psr17\StreamFactory;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use RuntimeException;

final class FactoriesTest extends TestCase
{
    public function testCreateStreamFromInvalidFileNameThrowsRuntimeException(): void
    {
        $this->assertStreamOpenFailureWithoutDiagnostics(
            static fn (): mixed => (new StreamFactory())->createStreamFromFile(''),
            'Unable to open file "" with mode "r".'
        );
    }

    public function testCreateStreamFromFileFailuresDoNotEmitWarnings(): void
    {
        $missingFile = __DIR__ . '/missing-stream-file';

        $this->assertStreamOpenFailureWithoutDiagnostics(
            static fn (): mixed => (new StreamFactory())->createStreamFromFile($missingFile),
            sprintf('Unable to open file "%s" with mode "r".', $missingFile)
        );
    }

    #[DataProvider('invalidStreamModeProvider')]
    public function testCreateStreamFromFileRejectsInvalidMode(string $mode): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new StreamFactory())->createStreamFromFile(__FILE__, $mode);
    }

    /** @return iterable<string, array{string}> */
    public static function invalidStreamModeProvider(): iterable
    {
        yield 'empty' => [''];
        yield 'unknown mode' => ['q'];
        yield 'uppercase read mode' => ['R'];
        yield 'modifier without base mode' => ['+'];
        yield 'binary modifier without base mode' => ['b'];
        yield 'leading whitespace' => [' r'];
    }

    public function testCreateStreamFromFileTreatsExistingFileWithExclusiveModeAsRuntimeFailure(): void
    {
        $this->assertStreamOpenFailureWithoutDiagnostics(
            static fn (): mixed => (new StreamFactory())->createStreamFromFile(__FILE__, 'x'),
            sprintf('Unable to open file "%s" with mode "x".', __FILE__)
        );
    }

    public function testResponseFactoryCreatesResponse(): void
    {
        $response = (new ResponseFactory())->createResponse(404);
        self::assertSame(404, $response->getStatusCode());
        self::assertSame('Not Found', $response->getReasonPhrase());
    }

    public function testResponseFactoryAcceptsExplicitReasonPhrase(): void
    {
        $response = (new ResponseFactory())->createResponse(200, 'Custom');
        self::assertSame('Custom', $response->getReasonPhrase());
    }

    public function testResponseFactoryValidatesTheCodeRegardlessOfTheReasonPhrase(): void
    {
        foreach (['' => '', 'x' => 'x'] as $reasonPhrase) {
            try {
                (new ResponseFactory())->createResponse(7000, $reasonPhrase);
                self::fail(sprintf('Expected code 7000 to be refused with reason phrase "%s".', $reasonPhrase));
            } catch (InvalidArgumentException $exception) {
                self::assertStringContainsString('7000', $exception->getMessage());
            }
        }
    }

    public function testResponseFactoryAcceptsANonStandardCodeWithAndWithoutAReasonPhrase(): void
    {
        self::assertSame(700, (new ResponseFactory())->createResponse(700)->getStatusCode());
        self::assertSame(700, (new ResponseFactory())->createResponse(700, 'x')->getStatusCode());
    }

    private function assertStreamOpenFailureWithoutDiagnostics(callable $operation, string $message): void
    {
        $diagnostics = [];
        $streamFactorySourceFile = (new ReflectionClass(StreamFactory::class))->getFileName();
        set_error_handler(static function (int $severity, string $error, string $file) use (&$diagnostics, $streamFactorySourceFile): bool {
            if ($file === $streamFactorySourceFile) {
                $diagnostics[] = $error;
            }
            return true;
        });
        $exception = null;
        try {
            try {
                $operation();
            } finally {
                restore_error_handler();
            }
        } catch (RuntimeException $runtimeException) {
            $exception = $runtimeException;
        }
        self::assertInstanceOf(RuntimeException::class, $exception);
        self::assertSame($message, $exception->getMessage());
        self::assertSame([], $diagnostics);
    }
}

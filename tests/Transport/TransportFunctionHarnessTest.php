<?php declare(strict_types=1);

namespace Domm98CZ\Curl\Tests\Transport;

require_once __DIR__ . '/TransportFunctionHarness.php';

use Domm98CZ\Curl\Transport\InterceptsTransportFunctions;
use Domm98CZ\Curl\Transport\TransportFunctionHarness;
use Domm98CZ\Curl\Transport\TransportFunctionInterceptor;
use LogicException;
use PHPUnit\Framework\Attributes\After;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class TransportFunctionHarnessTest extends TestCase
{
    public function testInterceptionAppliesAndReleaseRestoresTheUnderlyingFunctions(): void
    {
        $stream = fopen('php://temp', 'r+');
        if ($stream === false) {
            self::fail('Unable to open a temporary stream.');
        }

        try {
            $failure = new RuntimeException('intercepted write');
            TransportFunctionHarness::activate()->failWritesTo($stream, $failure);

            try {
                \Domm98CZ\Curl\Transport\fwrite($stream, 'blocked');
                self::fail('The namespaced fwrite() was not intercepted.');
            } catch (RuntimeException $exception) {
                self::assertSame($failure, $exception);
            }

            TransportFunctionHarness::release();

            self::assertSame(8, \Domm98CZ\Curl\Transport\fwrite($stream, 'released'));
            rewind($stream);
            self::assertSame('released', stream_get_contents($stream));
        } finally {
            TransportFunctionHarness::release();
            fclose($stream);
        }
    }

    public function testASecondActivationIsRefusedWhileAnInterceptorIsStillActive(): void
    {
        $active = TransportFunctionHarness::activate();

        try {
            TransportFunctionHarness::activate();
            self::fail('Expected a second activation to be refused while an interceptor is still active.');
        } catch (LogicException $exception) {
            self::assertStringContainsString('still active', $exception->getMessage());
        } finally {
            TransportFunctionHarness::release();
        }

        try {
            self::assertNotSame($active, TransportFunctionHarness::activate());
        } finally {
            TransportFunctionHarness::release();
        }
    }

    public function testTheReleaseHookIsRegisteredAndHandsInterceptionBackToTheNextOwner(): void
    {
        $releaseHook = new \ReflectionMethod(InterceptsTransportFunctions::class, 'releaseTransportFunctions');
        self::assertNotSame([], $releaseHook->getAttributes(After::class));

        $owner = new class {
            use InterceptsTransportFunctions;

            public function intercept(): TransportFunctionInterceptor
            {
                return $this->interceptTransportFunctions();
            }

            public function runReleaseHook(): void
            {
                $this->releaseTransportFunctions();
            }
        };

        $stream = fopen('php://temp', 'r+');
        if ($stream === false) {
            self::fail('Unable to open a temporary stream.');
        }

        try {
            $armed = $owner->intercept();
            $armed->failWritesTo($stream, new RuntimeException('armed by the previous owner'));
            $owner->runReleaseHook();

            self::assertSame(5, \Domm98CZ\Curl\Transport\fwrite($stream, 'clean'));
            self::assertNotSame($armed, TransportFunctionHarness::activate());
        } finally {
            TransportFunctionHarness::release();
            fclose($stream);
        }
    }
}

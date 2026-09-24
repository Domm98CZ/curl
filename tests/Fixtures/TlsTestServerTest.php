<?php declare(strict_types=1);

namespace Domm98CZ\Curl\Tests\Fixtures;

use PHPUnit\Framework\TestCase;
use RuntimeException;

final class TlsTestServerTest extends TestCase
{
    public function testStartClearsItsReadinessRequestAndStopReleasesThePort(): void
    {
        $port = $this->reservePort();
        $server = new TlsTestServer($port);

        try {
            $server->start();

            self::assertSame([], $server->requests());
            self::assertSame('tls-target-ok', file_get_contents($server->baseUrl . '/hit', false, $this->insecureTlsContext()));
            self::assertSame(['GET /hit'], $server->requests());
        } finally {
            $server->stop();
        }

        self::assertFalse($this->isPortListening($port));
    }

    public function testStartFailsWhenThePortIsAlreadyInUse(): void
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        if ($socket === false) {
            self::fail('Unable to reserve a local port.');
        }
        $address = stream_socket_get_name($socket, false);
        self::assertIsString($address);
        $port = (int) substr($address, strrpos($address, ':') + 1);

        try {
            $server = new TlsTestServer($port);

            $this->expectException(RuntimeException::class);
            // Both reachable throw sites interpolate the port, so only the wording separates the
            // pre-flight from a server that merely failed to come up in time.
            $this->expectExceptionMessage(sprintf('port %d is already in use', $port));
            $server->start();
        } finally {
            fclose($socket);
        }
    }

    private function reservePort(): int
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        if ($socket === false) {
            self::fail('Unable to reserve a local port.');
        }
        $address = stream_socket_get_name($socket, false);
        fclose($socket);

        self::assertIsString($address);
        return (int) substr($address, strrpos($address, ':') + 1);
    }

    private function isPortListening(int $port): bool
    {
        $connection = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.1);
        if ($connection === false) {
            return false;
        }

        fclose($connection);
        return true;
    }

    private function insecureTlsContext()
    {
        return stream_context_create(['ssl' => ['verify_peer' => false, 'verify_peer_name' => false]]);
    }
}

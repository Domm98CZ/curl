<?php declare(strict_types=1);

namespace Domm98CZ\Curl\Tests\Fixtures;

use PHPUnit\Framework\TestCase;
use RuntimeException;

final class KeepAliveTestServerTest extends TestCase
{
    public function testStartServesRequestsAndStopReleasesThePort(): void
    {
        $port = $this->reservePort();
        $server = new KeepAliveTestServer($port);

        try {
            $server->start();

            self::assertStringContainsString("HTTP/1.1 200 OK\r\n", $this->request($port, 'GET'));
        } finally {
            $server->stop();
        }

        self::assertFalse($this->isPortListening($port));
    }

    public function testServerServesAnotherConnectionAfterAnIncompleteContentLengthUpload(): void
    {
        $port = $this->reservePort();
        $server = new KeepAliveTestServer($port);

        try {
            $server->start();
            $upload = stream_socket_client(sprintf('tcp://127.0.0.1:%d', $port));
            if ($upload === false) {
                self::fail('Unable to connect to the keep-alive test server.');
            }
            fwrite($upload, "POST / HTTP/1.1\r\nHost: 127.0.0.1\r\nContent-Length: 100\r\n\r\nshort");
            fclose($upload);

            self::assertStringContainsString("X-Echo-Method: GET\r\n", $this->request($port, 'GET'));
        } finally {
            $server->stop();
        }
    }

    public function testServerServesAnotherConnectionAfterAnIncompleteChunkedUpload(): void
    {
        $port = $this->reservePort();
        $server = new KeepAliveTestServer($port);

        try {
            $server->start();
            $upload = stream_socket_client(sprintf('tcp://127.0.0.1:%d', $port));
            if ($upload === false) {
                self::fail('Unable to connect to the keep-alive test server.');
            }
            fwrite($upload, "POST / HTTP/1.1\r\nHost: 127.0.0.1\r\nTransfer-Encoding: chunked\r\n\r\nA\r\nshort");
            fclose($upload);

            self::assertStringContainsString("X-Echo-Method: GET\r\n", $this->request($port, 'GET'));
        } finally {
            $server->stop();
        }
    }

    public function testServerSanitizesTheMethodBeforeEchoingIt(): void
    {
        $port = $this->reservePort();
        $server = new KeepAliveTestServer($port);

        try {
            $server->start();

            $response = $this->request($port, "GET\r\nInjected: yes");

            self::assertStringContainsString("X-Echo-Method: GET\r\n", $response);
            self::assertStringNotContainsString("\r\nInjected:", $response);
        } finally {
            $server->stop();
        }
    }

    public function testIdleServerStopsAfterItsDeadlineIsResetByARequest(): void
    {
        $port = $this->reservePort();
        $server = new KeepAliveTestServer($port, 0.6);

        try {
            $server->start();
            usleep(200_000);
            $this->request($port, 'GET');
            usleep(450_000);
            self::assertTrue($this->isPortListening($port));
            usleep(300_000);
            self::assertFalse($this->isPortListening($port));
        } finally {
            $server->stop();
        }
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
            $server = new KeepAliveTestServer($port);

            $this->expectException(RuntimeException::class);
            // Both reachable throw sites interpolate the port, so only the wording separates the
            // pre-flight from a server that merely failed to come up in time.
            $this->expectExceptionMessage(sprintf('port %d is already in use', $port));
            $server->start();
        } finally {
            fclose($socket);
        }
    }

    private function request(int $port, string $method): string
    {
        $connection = stream_socket_client(sprintf('tcp://127.0.0.1:%d', $port));
        if ($connection === false) {
            throw new RuntimeException('Unable to connect to the keep-alive test server.');
        }

        try {
            stream_set_timeout($connection, 2);
            fwrite($connection, "{$method} / HTTP/1.1\r\nHost: 127.0.0.1\r\n\r\n");
            $response = '';
            while (!str_contains($response, "\r\n\r\n")) {
                $line = fgets($connection, 8192);
                if ($line === false || $line === '') {
                    break;
                }
                $response .= $line;
            }

            return $response;
        } finally {
            fclose($connection);
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
}

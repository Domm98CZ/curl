<?php declare(strict_types=1);

namespace Domm98CZ\Curl\Tests\Fixtures;

use RuntimeException;
use Throwable;

final class ContinueTestServer
{
    public const INTERIM_BLOCK = "HTTP/1.1 100 Continue\r\n\r\n";

    private mixed $process = null;
    public readonly string $baseUrl;

    public function __construct(private readonly int $port = 8117, private readonly float $idleTimeout = 30.0)
    {
        $this->baseUrl = sprintf('http://127.0.0.1:%d', $this->port);
        register_shutdown_function(function (): void {
            $this->stop();
        });
    }

    public function start(): void
    {
        $this->assertPortIsAvailable();

        $process = proc_open(
            ['php', __DIR__ . '/continue-server.php', (string) $this->port, (string) $this->idleTimeout],
            [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
            $pipes,
        );

        if (!is_resource($process)) {
            throw new RuntimeException('Unable to start the interim-response test server.');
        }

        $this->process = $process;
        try {
            $this->waitUntilReady();
        } catch (Throwable $exception) {
            $this->stop();
            throw $exception;
        }
    }

    public function stop(): void
    {
        $process = $this->process;
        $this->process = null;

        if (!is_resource($process)) {
            return;
        }

        $status = proc_get_status($process);
        if (($status['running'] ?? false) === true) {
            proc_terminate($process);
            if (!$this->waitForProcessToStop($process)) {
                proc_terminate($process, 9);
                $this->waitForProcessToStop($process);
            }
        }
        proc_close($process);
    }

    private function waitUntilReady(): void
    {
        $deadline = microtime(true) + 5.0;
        while (microtime(true) < $deadline) {
            $connection = @stream_socket_client(sprintf('tcp://127.0.0.1:%d', $this->port), $errno, $errstr, 0.1);
            if ($connection !== false) {
                stream_set_timeout($connection, 0, 200_000);
                $written = @fwrite($connection, "GET /with-interim HTTP/1.1\r\nHost: 127.0.0.1\r\n\r\n");
                $response = $written === false ? '' : $this->readResponseHeaders($connection);
                fclose($connection);
                if (str_starts_with($response, self::INTERIM_BLOCK)) {
                    return;
                }
            }
            usleep(50_000);
        }

        throw new RuntimeException(
            sprintf('Interim-response test server on port %d did not start in time.', $this->port)
        );
    }

    private function assertPortIsAvailable(): void
    {
        $connection = @fsockopen('127.0.0.1', $this->port, $errno, $errstr, 0.1);
        if ($connection === false) {
            return;
        }

        fclose($connection);
        throw new RuntimeException(
            sprintf('Interim-response test server port %d is already in use.', $this->port)
        );
    }

    private function waitForProcessToStop(mixed $process): bool
    {
        $deadline = microtime(true) + 1.0;
        while (microtime(true) < $deadline) {
            $status = proc_get_status($process);
            if (($status['running'] ?? false) !== true) {
                return true;
            }
            usleep(10_000);
        }

        $status = proc_get_status($process);
        return ($status['running'] ?? false) !== true;
    }

    /** @param resource $connection */
    private function readResponseHeaders($connection): string
    {
        $response = '';
        while (!str_contains($response, "\r\n\r\n")) {
            $line = fgets($connection, 8192);
            if ($line === false || $line === '') {
                break;
            }
            $response .= $line;
        }

        return $response;
    }
}

<?php declare(strict_types=1);

namespace Domm98CZ\Curl\Tests\Fixtures;

use RuntimeException;

final class TestServer
{
    private mixed $process = null;
    private readonly string $logFile;
    public readonly string $baseUrl;

    public function __construct(private readonly int $port = 8099)
    {
        $this->baseUrl = sprintf('http://127.0.0.1:%d', $this->port);
        $this->logFile = sys_get_temp_dir() . sprintf('/curl-test-server-%d-%s.log', $this->port, bin2hex(random_bytes(4)));
        register_shutdown_function(function (): void {
            $this->stop();
        });
    }

    public function start(): void
    {
        $this->assertPortIsAvailable();

        $router = __DIR__ . '/test-server.php';
        $environment = getenv();
        if (!is_array($environment)) {
            $environment = [];
        }
        $environment['TEST_SERVER_LOG'] = $this->logFile;
        $process = proc_open(
            ['php', '-S', sprintf('127.0.0.1:%d', $this->port), $router],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            null,
            $environment
        );

        if (!is_resource($process)) {
            throw new RuntimeException('Unable to start the local test server.');
        }

        foreach ($pipes as $pipe) {
            fclose($pipe);
        }

        $this->process = $process;
        try {
            $this->waitUntilReady();
        } catch (\Throwable $exception) {
            $this->stop();
            throw $exception;
        }
    }

    public function stop(): void
    {
        $process = $this->process;
        $this->process = null;

        try {
            if (is_resource($process)) {
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
        } finally {
            if (is_file($this->logFile)) {
                unlink($this->logFile);
            }
        }
    }

    public function clearRequests(): void
    {
        if (file_put_contents($this->logFile, '') === false) {
            throw new RuntimeException('Unable to clear the local test server request log.');
        }
    }

    /** @return string[] */
    public function requests(): array
    {
        $contents = @file_get_contents($this->logFile);
        if ($contents === false || $contents === '') {
            return [];
        }
        return preg_split('/\R/', trim($contents), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    }

    private function waitUntilReady(): void
    {
        $readyPath = '/ok?ready=' . bin2hex(random_bytes(8));
        $expectedRequest = 'GET ' . $readyPath . ' HTTP/1.1';
        $deadline = microtime(true) + 3.0;
        while (microtime(true) < $deadline) {
            @file_get_contents($this->baseUrl . $readyPath);
            if (in_array($expectedRequest, $this->requests(), true)) {
                $this->clearRequests();
                return;
            }
            usleep(50_000);
        }
        throw new RuntimeException(sprintf('Local test server on port %d did not start in time.', $this->port));
    }

    private function assertPortIsAvailable(): void
    {
        $connection = @fsockopen('127.0.0.1', $this->port, $errno, $errstr, 0.1);
        if ($connection === false) {
            return;
        }

        fclose($connection);
        throw new RuntimeException(sprintf('Local test server port %d is already in use.', $this->port));
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
}

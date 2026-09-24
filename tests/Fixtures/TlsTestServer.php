<?php declare(strict_types=1);

namespace Domm98CZ\Curl\Tests\Fixtures;

use RuntimeException;

final class TlsTestServer
{
    private mixed $process = null;
    private readonly string $logFile;
    private readonly string $certificateFile;
    public readonly string $baseUrl;

    public function __construct(private readonly int $port)
    {
        $suffix = sprintf('%d-%s', $this->port, bin2hex(random_bytes(4)));
        $this->baseUrl = sprintf('https://127.0.0.1:%d', $this->port);
        $this->logFile = sys_get_temp_dir() . '/curl-tls-test-server-' . $suffix . '.log';
        $this->certificateFile = sys_get_temp_dir() . '/curl-tls-test-server-' . $suffix . '.pem';
        register_shutdown_function(function (): void {
            $this->stop();
        });
    }

    public function start(): void
    {
        $this->assertPortIsAvailable();
        $this->createCertificate();
        $server = __DIR__ . '/tls-test-server.php';
        $process = proc_open(
            ['php', $server, (string) $this->port, $this->certificateFile, $this->logFile],
            [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
            $pipes,
        );

        if (!is_resource($process)) {
            $this->removeTemporaryFiles();
            throw new RuntimeException('Unable to start the local TLS test server.');
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
            $this->removeTemporaryFiles();
        }
    }

    public function clearRequests(): void
    {
        if (file_put_contents($this->logFile, '') === false) {
            throw new RuntimeException('Unable to clear the local TLS test server request log.');
        }
    }

    /** @return string[] */
    public function requests(): array
    {
        $contents = file_get_contents($this->logFile);
        if ($contents === false || $contents === '') {
            return [];
        }
        return preg_split('/\R/', trim($contents), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    }

    private function createCertificate(): void
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        if ($key === false) {
            throw new RuntimeException('Unable to create a TLS test private key.');
        }
        $request = openssl_csr_new(['commonName' => '127.0.0.1'], $key, ['digest_alg' => 'sha256']);
        if ($request === false) {
            throw new RuntimeException('Unable to create a TLS test certificate request.');
        }
        $certificate = openssl_csr_sign($request, null, $key, 1, ['digest_alg' => 'sha256']);
        if ($certificate === false) {
            throw new RuntimeException('Unable to sign the TLS test certificate.');
        }
        if (!openssl_x509_export($certificate, $certificatePem) || !openssl_pkey_export($key, $privateKeyPem)) {
            throw new RuntimeException('Unable to export the TLS test certificate.');
        }
        if (file_put_contents($this->certificateFile, $certificatePem . $privateKeyPem) === false) {
            throw new RuntimeException('Unable to write the TLS test certificate.');
        }
    }

    private function waitUntilReady(): void
    {
        $readyPath = '/hit?ready=' . bin2hex(random_bytes(8));
        $expectedRequest = 'GET ' . $readyPath;
        $deadline = microtime(true) + 3.0;
        while (microtime(true) < $deadline) {
            $context = stream_context_create(['ssl' => ['verify_peer' => false, 'verify_peer_name' => false]]);
            $connection = @stream_socket_client(
                sprintf('tls://127.0.0.1:%d', $this->port),
                $errno,
                $errstr,
                0.1,
                STREAM_CLIENT_CONNECT,
                $context,
            );
            if ($connection !== false) {
                fwrite($connection, "GET {$readyPath} HTTP/1.1\r\nHost: 127.0.0.1\r\nConnection: close\r\n\r\n");
                stream_get_contents($connection);
                fclose($connection);
                if (in_array($expectedRequest, $this->requests(), true)) {
                    $this->clearRequests();
                    return;
                }
            }
            usleep(50_000);
        }
        throw new RuntimeException(sprintf('Local TLS test server on port %d did not start in time.', $this->port));
    }

    private function assertPortIsAvailable(): void
    {
        $connection = @fsockopen('127.0.0.1', $this->port, $errno, $errstr, 0.1);
        if ($connection === false) {
            return;
        }

        fclose($connection);
        throw new RuntimeException(sprintf('Local TLS test server port %d is already in use.', $this->port));
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

    private function removeTemporaryFiles(): void
    {
        foreach ([$this->logFile, $this->certificateFile] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
    }
}

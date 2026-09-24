<?php declare(strict_types=1);

// A persistent connection exposes responses whose end is not signalled by connection closure.

$port = (int) ($argv[1] ?? 8114);
$idleTimeout = (float) ($argv[2] ?? 30.0);
$idleTimeout = $idleTimeout > 0 ? $idleTimeout : 30.0;
$server = @stream_socket_server(sprintf('tcp://127.0.0.1:%d', $port), $errno, $errstr);
if ($server === false) {
    fwrite(STDERR, sprintf("keep-alive server failed to bind: %s\n", $errstr));
    exit(1);
}

$body = str_repeat('a', 2_097_152);
$idleDeadline = microtime(true) + $idleTimeout;

/** @param resource $connection */
function writeAll($connection, string $data): bool
{
    $offset = 0;
    $length = strlen($data);
    while ($offset < $length) {
        $written = @fwrite($connection, substr($data, $offset, 65_536));
        if ($written === false || $written === 0) {
            return false;
        }
        $offset += $written;
    }
    return true;
}

while (microtime(true) < $idleDeadline) {
    $connection = @stream_socket_accept($server, 0.1);
    if ($connection === false) {
        continue;
    }
    stream_set_timeout($connection, 2);

    while (true) {
        $head = '';
        while (!str_contains($head, "\r\n\r\n")) {
            $line = fgets($connection, 8192);
            if ($line === false || $line === '') {
                break 2;
            }
            $head .= $line;
        }

        $requestLine = (string) strtok($head, "\r\n");
        $method = strtoupper((string) strtok($requestLine, ' '));
        if (preg_match('/^[A-Z]+$/', $method) !== 1) {
            $method = 'INVALID';
        }

        if (preg_match('/^Content-Length:\s*(\d+)/mi', $head, $matches) === 1) {
            $remaining = (int) $matches[1];
            while ($remaining > 0) {
                $chunk = fread($connection, min(65_536, $remaining));
                if ($chunk === false || $chunk === '') {
                    break 2;
                }
                $remaining -= strlen($chunk);
            }
        } elseif (stripos($head, 'Transfer-Encoding: chunked') !== false) {
            while (($sizeLine = fgets($connection, 128)) !== false) {
                $size = (int) hexdec(trim($sizeLine));
                if ($size === 0) {
                    fgets($connection, 8);
                    break;
                }
                while ($size > 0) {
                    $chunk = fread($connection, min(65_536, $size));
                    if ($chunk === false || $chunk === '') {
                        break 3;
                    }
                    $size -= strlen($chunk);
                }
                fgets($connection, 8);
            }
        }

        $response = "HTTP/1.1 200 OK\r\n"
            . "Content-Type: application/octet-stream\r\n"
            . sprintf("Content-Length: %d\r\n", strlen($body))
            . "X-Echo-Method: {$method}\r\n"
            . "Connection: keep-alive\r\n"
            . "\r\n";
        // RFC 9110: a HEAD response carries the headers of the equivalent GET but never the body.
        if ($method !== 'HEAD') {
            $response .= $body;
        }

        $writeSucceeded = writeAll($connection, $response);
        $idleDeadline = microtime(true) + $idleTimeout;
        if (!$writeSucceeded) {
            break;
        }
    }

    fclose($connection);
}

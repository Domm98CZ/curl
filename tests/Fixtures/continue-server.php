<?php declare(strict_types=1);

// php -S never emits an interim response, so the 1xx block a client has to skip before the final
// one only exists on a socket the fixture drives itself.

$port = (int) ($argv[1] ?? 8117);
$idleTimeout = (float) ($argv[2] ?? 30.0);
$idleTimeout = $idleTimeout > 0 ? $idleTimeout : 30.0;
$server = @stream_socket_server(sprintf('tcp://127.0.0.1:%d', $port), $errno, $errstr);
if ($server === false) {
    fwrite(STDERR, sprintf("continue server failed to bind: %s\n", $errstr));
    exit(1);
}

const INTERIM_BLOCK = "HTTP/1.1 100 Continue\r\n\r\n";
const FINAL_BODY = 'final';

/** @param resource $connection */
function drainRequestBody($connection, string $head): void
{
    if (preg_match('/^Content-Length:\s*(\d+)/mi', $head, $matches) === 1) {
        $remaining = (int) $matches[1];
        while ($remaining > 0) {
            $chunk = fread($connection, min(65_536, $remaining));
            if ($chunk === false || $chunk === '') {
                return;
            }
            $remaining -= strlen($chunk);
        }
        return;
    }

    if (stripos($head, 'Transfer-Encoding: chunked') === false) {
        return;
    }

    while (($sizeLine = fgets($connection, 128)) !== false) {
        $size = (int) hexdec(trim($sizeLine));
        if ($size === 0) {
            fgets($connection, 8);
            return;
        }
        while ($size > 0) {
            $chunk = fread($connection, min(65_536, $size));
            if ($chunk === false || $chunk === '') {
                return;
            }
            $size -= strlen($chunk);
        }
        fgets($connection, 8);
    }
}

$idleDeadline = microtime(true) + $idleTimeout;

while (microtime(true) < $idleDeadline) {
    $connection = @stream_socket_accept($server, 0.1);
    if ($connection === false) {
        continue;
    }
    stream_set_timeout($connection, 2);

    $head = '';
    while (!str_contains($head, "\r\n\r\n")) {
        $line = fgets($connection, 8192);
        if ($line === false || $line === '') {
            break;
        }
        $head .= $line;
    }

    $requestLine = (string) strtok($head, "\r\n");
    strtok($requestLine, ' ');
    $target = (string) strtok(' ');
    parse_str((string) parse_url($target, PHP_URL_QUERY), $query);
    $requestedInterims = isset($query['interim']) && is_string($query['interim']) ? (int) $query['interim'] : 1;
    $interimCount = parse_url($target, PHP_URL_PATH) === '/with-interim'
        ? max(1, min(8, $requestedInterims))
        : 0;

    for ($sent = 0; $sent < $interimCount; $sent++) {
        @fwrite($connection, INTERIM_BLOCK);
    }

    drainRequestBody($connection, $head);

    @fwrite($connection, "HTTP/1.1 200 OK\r\n"
        . "Content-Type: text/plain\r\n"
        . sprintf("Content-Length: %d\r\n", strlen(FINAL_BODY))
        . "Connection: close\r\n"
        . "\r\n"
        . FINAL_BODY);

    fclose($connection);
    $idleDeadline = microtime(true) + $idleTimeout;
}

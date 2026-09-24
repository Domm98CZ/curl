<?php declare(strict_types=1);

$port = isset($argv[1]) ? (int) $argv[1] : 0;
$certificateFile = $argv[2] ?? '';
$logFile = $argv[3] ?? '';

$context = stream_context_create(['ssl' => ['local_cert' => $certificateFile, 'allow_self_signed' => true]]);
$server = stream_socket_server(
    sprintf('tls://127.0.0.1:%d', $port),
    $errno,
    $error,
    STREAM_SERVER_BIND | STREAM_SERVER_LISTEN,
    $context,
);
if ($server === false) {
    fwrite(STDERR, sprintf("TLS server failed: %d %s\n", $errno, $error));
    exit(1);
}

while (true) {
    $connection = @stream_socket_accept($server, -1);
    if ($connection === false) {
        continue;
    }
    $requestLine = fgets($connection);
    if ($requestLine === false) {
        fclose($connection);
        continue;
    }
    while (($line = fgets($connection)) !== false && trim($line) !== '') {
    }

    $parts = explode(' ', trim($requestLine));
    $method = $parts[0] ?? '';
    $target = $parts[1] ?? '/';
    file_put_contents($logFile, $method . ' ' . $target . "\n", FILE_APPEND | LOCK_EX);

    $path = parse_url($target, PHP_URL_PATH);
    parse_str((string) parse_url($target, PHP_URL_QUERY), $query);
    $status = '404 Not Found';
    $headers = [];
    $body = 'not-found';

    if ($path === '/hit') {
        $status = '200 OK';
        $body = 'tls-target-ok';
    } elseif ($path === '/redirect-to' && isset($query['url']) && is_string($query['url'])) {
        $status = '302 Found';
        $headers[] = 'Location: ' . $query['url'];
        $body = '';
    }

    $headers[] = 'Content-Length: ' . strlen($body);
    $headers[] = 'Connection: close';
    fwrite($connection, "HTTP/1.1 {$status}\r\n" . implode("\r\n", $headers) . "\r\n\r\n" . $body);
    fclose($connection);
}

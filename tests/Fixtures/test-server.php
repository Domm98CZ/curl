<?php declare(strict_types=1);

$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

$logFile = getenv('TEST_SERVER_LOG');
if (is_string($logFile) && $logFile !== '') {
    file_put_contents(
        $logFile,
        $_SERVER['REQUEST_METHOD'] . ' ' . $_SERVER['REQUEST_URI'] . ' ' . $_SERVER['SERVER_PROTOCOL'] . "\n",
        FILE_APPEND | LOCK_EX
    );
}

if ($uri === '/ok') {
    header('X-Test: 1');
    echo 'ok';
    exit;
}

if ($uri === '/redirect') {
    header('Location: /ok', true, 301);
    exit;
}

if ($uri === '/redirect-to' && isset($_GET['url']) && is_string($_GET['url'])) {
    header('Location: ' . $_GET['url'], true, 302);
    exit;
}

if ($uri === '/hit') {
    echo 'target-ok';
    exit;
}

if ($uri === '/redirect-to-file') {
    header('Location: file:///etc/hostname', true, 302);
    exit;
}

if ($uri === '/redirect-307') {
    header('Location: /echo-method', true, 307);
    exit;
}

if ($uri === '/redirect-302') {
    header('Location: /echo-method', true, 302);
    exit;
}

if ($uri === '/redirect-303') {
    header('Location: /echo-method', true, 303);
    exit;
}

if (preg_match('#^/status/(\d{3})$#', $uri, $m)) {
    http_response_code((int) $m[1]);
    echo 'status-' . $m[1];
    exit;
}

if ($uri === '/echo') {
    header('Content-Type: text/plain');
    echo file_get_contents('php://input');
    exit;
}

if ($uri === '/echo-method') {
    header('Content-Type: text/plain');
    echo $_SERVER['REQUEST_METHOD'] . ':' . file_get_contents('php://input');
    exit;
}

if ($uri === '/echo-request') {
    header('Content-Type: application/json');
    echo json_encode([
        'method' => $_SERVER['REQUEST_METHOD'],
        'body' => file_get_contents('php://input'),
        'contentLength' => $_SERVER['CONTENT_LENGTH'] ?? '',
        'transferEncoding' => $_SERVER['HTTP_TRANSFER_ENCODING'] ?? '',
        'expect' => $_SERVER['HTTP_EXPECT'] ?? '',
    ], JSON_THROW_ON_ERROR);
    exit;
}

if ($uri === '/echo-x-foo') {
    header('Content-Type: application/json');
    echo json_encode([
        'present' => array_key_exists('HTTP_X_FOO', $_SERVER),
        'value' => $_SERVER['HTTP_X_FOO'] ?? null,
    ], JSON_THROW_ON_ERROR);
    exit;
}

if ($uri === '/slow') {
    usleep(1_500_000);
    echo 'slow-ok';
    exit;
}

if ($uri === '/gzip') {
    $payload = str_repeat('compressible-payload ', 64);
    header('Content-Type: text/plain');
    header('Content-Encoding: gzip');
    $encoded = gzencode($payload);
    header('Content-Length: ' . strlen($encoded));
    echo $encoded;
    exit;
}

if ($uri === '/large-plain') {
    $size = 2_097_152;
    header('Content-Type: application/octet-stream');
    header('Content-Length: ' . $size);
    if ($_SERVER['REQUEST_METHOD'] !== 'HEAD') {
        echo str_repeat('a', $size);
    }
    exit;
}

if ($uri === '/response-size') {
    $headerCount = isset($_GET['headers']) ? max(0, min(100, (int) $_GET['headers'])) : 0;
    $headerBytes = isset($_GET['headerBytes']) ? max(0, min(1_024, (int) $_GET['headerBytes'])) : 0;
    $bodyBytes = isset($_GET['bodyBytes']) ? max(0, min(16_384, (int) $_GET['bodyBytes'])) : 0;
    for ($index = 0; $index < $headerCount; $index++) {
        header(sprintf('X-Fill-%03d: %s', $index, str_repeat('h', $headerBytes)), false);
    }
    echo str_repeat('b', $bodyBytes);
    exit;
}

if ($uri === '/gzip-bomb-content-length' || $uri === '/gzip-bomb-chunked') {
    $payload = str_repeat('a', 52_428_800);
    $encoded = gzencode($payload, 9);
    if ($encoded === false) {
        http_response_code(500);
        exit;
    }

    header('Content-Type: application/octet-stream');
    header('Content-Encoding: gzip');
    if ($uri === '/gzip-bomb-content-length') {
        header('Content-Length: ' . strlen($encoded));
        echo $encoded;
        exit;
    }

    header('Transfer-Encoding: chunked');
    foreach (str_split($encoded, 4096) as $chunk) {
        echo dechex(strlen($chunk)) . "\r\n" . $chunk . "\r\n";
    }
    echo "0\r\n\r\n";
    exit;
}

if ($uri === '/stream') {
    header('Content-Type: text/event-stream');
    header('X-Accel-Buffering: no');
    $name = isset($_GET['name']) && is_string($_GET['name']) ? $_GET['name'] : 'chunk';
    while (ob_get_level() > 0) {
        ob_end_flush();
    }
    ob_implicit_flush(true);
    for ($i = 1; $i <= 4; $i++) {
        echo "data: {$name}-{$i}\n\n";
        flush();
        usleep(120_000);
    }
    exit;
}

http_response_code(404);
echo 'not-found';

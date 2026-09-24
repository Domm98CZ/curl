<?php declare(strict_types=1);

namespace Domm98CZ\Curl\Tests\Conformance\Psr7;

use Domm98CZ\Curl\Psr17\StreamFactory;
use Domm98CZ\Curl\Psr7\Stream;
use Http\Psr7Test\StreamIntegrationTest;
use Psr\Http\Message\StreamInterface;

final class StreamConformanceTest extends StreamIntegrationTest
{
    private const REMOTE_HOST = 'raw.githubusercontent.com';

    protected function setUp(): void
    {
        parent::setUp();

        if (self::remoteIsReachable()) {
            return;
        }

        // These four upstream cases read a real URL. Without the network fopen() returns false and the
        // adapter below is handed a bool, which ends the run in a TypeError rather than in a skip.
        foreach ([
            'testIsNotSeekable', 'testIsNotWritable', 'testIsNotReadable', 'testRewindNotSeekable',
        ] as $test) {
            $this->skippedTests[$test] = sprintf('%s is unreachable from this machine.', self::REMOTE_HOST);
        }
    }

    private static function remoteIsReachable(): bool
    {
        static $reachable = null;
        if ($reachable !== null) {
            return $reachable;
        }

        $socket = @fsockopen('tcp://' . self::REMOTE_HOST, 443, $errorNumber, $errorMessage, 2.0);
        if ($socket === false) {
            return $reachable = false;
        }
        fclose($socket);

        return $reachable = true;
    }

    public function createStream($data): StreamInterface
    {
        if ($data instanceof StreamInterface) {
            return $data;
        }

        if (is_resource($data)) {
            return new Stream($data);
        }

        return (new StreamFactory())->createStream($data);
    }
}

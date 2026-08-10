<?php

declare(strict_types=1);

namespace Tracing\Sdk\Tests;

use PHPUnit\Framework\TestCase;
use Tracing\Sdk\Auth\ApiTokenAuthenticator;
use Tracing\Sdk\Transport\CurlHttpTransport;

/**
 * Spins up PHP's built-in web server against a tiny router that echoes back
 * the request it received, so we can assert on the real URL path/method/body
 * the transport sends rather than mocking cURL.
 */
class CurlHttpTransportTest extends TestCase
{
    /** @var resource */
    private static $serverProcess;

    /** @var string */
    private static $baseUrl;

    public static function setUpBeforeClass(): void
    {
        $port = self::findFreePort();
        self::$baseUrl = 'http://127.0.0.1:' . $port;
        $router = __DIR__ . '/fixtures/echo-request-router.php';

        self::$serverProcess = proc_open(
            [PHP_BINARY, '-S', '127.0.0.1:' . $port, $router],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes
        );

        self::waitUntilUp(self::$baseUrl);
    }

    public static function tearDownAfterClass(): void
    {
        if (is_resource(self::$serverProcess)) {
            proc_terminate(self::$serverProcess);
            proc_close(self::$serverProcess);
        }
    }

    public function testSendSingleUsesApiAnchorsEndpoint(): void
    {
        $transport = new CurlHttpTransport(self::$baseUrl, new ApiTokenAuthenticator('token'));

        $result = $transport->sendSingle(['hash' => '0xabc', 'signingTime' => 1]);

        $this->assertSame('/api/anchors', $result['body']['path']);
        $this->assertSame('POST', $result['body']['method']);
        $this->assertSame(['hash' => '0xabc', 'signingTime' => 1], $result['body']['body']);
    }

    public function testSendBatchUsesApiAnchorsBatchEndpoint(): void
    {
        $transport = new CurlHttpTransport(self::$baseUrl, new ApiTokenAuthenticator('token'));

        $records = [['hash' => '0xabc', 'signingTime' => 1], ['hash' => '0xdef', 'signingTime' => 2]];
        $result = $transport->sendBatch($records);

        $this->assertSame('/api/anchors/batch', $result['body']['path']);
        $this->assertSame('POST', $result['body']['method']);
        $this->assertSame(['records' => $records], $result['body']['body']);
    }

    private static function findFreePort(): int
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        $name = stream_socket_get_name($socket, false);
        fclose($socket);

        return (int) substr($name, strrpos($name, ':') + 1);
    }

    private static function waitUntilUp(string $baseUrl): void
    {
        $deadline = microtime(true) + 5;

        while (microtime(true) < $deadline) {
            $ch = curl_init($baseUrl . '/api/anchors');
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, '{}');
            curl_exec($ch);
            $ok = curl_errno($ch) === 0;
            curl_close($ch);

            if ($ok) {
                return;
            }

            usleep(20000);
        }

        throw new \RuntimeException('Built-in PHP server did not start in time');
    }
}

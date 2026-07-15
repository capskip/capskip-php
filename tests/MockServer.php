<?php

declare(strict_types=1);

namespace CapSkip\Tests;

/**
 * Boots the mock CapSkip server (tests/server/router.php) via PHP's built-in web
 * server on a free loopback port, so the integration tests can drive the real
 * HTTP layer end to end.
 */
class MockServer
{
    public const CODE = 'SOLVED_TOKEN_abc123';
    public const USER_AGENT = 'CapSkipUA/1.0';
    public const PNG_BASE64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==';

    public string $host = '127.0.0.1';
    public int $port = 0;

    /** @var resource|null */
    private $process;
    /** @var array<int, resource> */
    private array $pipes = [];
    private string $stateDir = '';

    public function start(): void
    {
        $this->port = self::freePort();
        $this->stateDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'capskip-mock-' . bin2hex(random_bytes(6));
        @mkdir($this->stateDir, 0777, true);

        $router = __DIR__ . DIRECTORY_SEPARATOR . 'server' . DIRECTORY_SEPARATOR . 'router.php';
        $command = [PHP_BINARY, '-S', $this->host . ':' . $this->port, $router];

        $env = getenv();
        $env['CAPSKIP_STATE_DIR'] = $this->stateDir;

        // The built-in server logs a line per request to stderr. Redirect stdout
        // and stderr to a log file (rather than unread pipes) so their buffers
        // never fill and deadlock the server after a burst of requests.
        $nullDevice = DIRECTORY_SEPARATOR === '\\' ? 'NUL' : '/dev/null';
        $logFile = $this->stateDir . DIRECTORY_SEPARATOR . 'server.log';
        $descriptors = [
            0 => ['file', $nullDevice, 'r'],
            1 => ['file', $logFile, 'a'],
            2 => ['file', $logFile, 'a'],
        ];

        $process = proc_open($command, $descriptors, $this->pipes, null, $env);
        if (!is_resource($process)) {
            throw new \RuntimeException('could not start the mock CapSkip server');
        }
        $this->process = $process;

        $deadline = microtime(true) + 10.0;
        while (microtime(true) < $deadline) {
            $conn = @fsockopen($this->host, $this->port, $errno, $errstr, 0.2);
            if ($conn) {
                fclose($conn);

                return;
            }
            usleep(100_000);
        }

        $this->stop();

        throw new \RuntimeException('mock CapSkip server did not become reachable');
    }

    public function stop(): void
    {
        foreach ($this->pipes as $pipe) {
            if (is_resource($pipe)) {
                fclose($pipe);
            }
        }
        $this->pipes = [];

        if (is_resource($this->process)) {
            proc_terminate($this->process);
            proc_close($this->process);
            $this->process = null;
        }

        if ($this->stateDir !== '' && is_dir($this->stateDir)) {
            foreach (glob($this->stateDir . DIRECTORY_SEPARATOR . '*') ?: [] as $file) {
                @unlink($file);
            }
            @rmdir($this->stateDir);
        }
    }

    public function baseUrl(): string
    {
        return "http://{$this->host}:{$this->port}";
    }

    public static function png(): string
    {
        return base64_decode(self::PNG_BASE64);
    }

    private static function freePort(): int
    {
        $sock = @stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        if ($sock === false) {
            throw new \RuntimeException("could not allocate a free port: {$errstr}");
        }
        $name = stream_socket_get_name($sock, false);
        fclose($sock);

        return (int) substr($name, strrpos($name, ':') + 1);
    }
}

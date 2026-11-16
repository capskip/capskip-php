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
    public const ALTCHA_NUMBER = 9661;
    public const ALTCHA_TOKEN = 'eyJhbGdvcml0aG0iOiJTSEEtMjU2IiwiY2hhbGxlbmdlIjoiM2RkMjgyNTNiZTZjYzBjNTRkOTVmN2Y5OGM1MTdlNjgiLCJudW1iZXIiOjk2NjEsInNhbHQiOiI0NmQ1YjFjODg3MWU1MTUyZDkwMmVlM2Y/ZXhwaXJlcz0xODkzNDU2MDAwIiwic2lnbmF0dXJlIjoiNGIxY2YwZTBiZTBmNGU1MjQ3ZTUwYjBmOWE0NDk4MzAiLCJ0b29rIjoxNi41OH0=';
    public const CAPY_SOLUTION_JSON = '{"captchakey":"PUZZLE_Abc1dEFghIJKLM2no34P56q7rStu8v","challengekey":"BalY2gJaI8uA2SGVOZhqBQ3V0CYSNNGP","answer":"0xax8ex0xax84x0xkx7qx0x18x76x0x1ix6sx0x26x68x0x2gx5kx0x34x50x","respKey":""}';
    public const CAPTCHAFOX_TOKEN = '177f50c25b845601e5c779cdb51b040d523e8ab69efb4d5b343e28df07d05076';
    public const CAPTCHAFOX_USER_AGENT = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/146.0.0.0 Safari/537.36';
    public const FRIENDLY_CAPTCHA_TOKEN = 'c62c4da36bbaf7f253873035832709ef.aqwpWwdbzRWKY/UQAQwwpgAAAAAAAAAAM7hBvJOzqjc=.AAAAAArcCQABAAAAxv8QAAIAAACKYRgA.AgAB';
    public const AMAZON_WAF_SOLUTION_JSON = '{"captcha_voucher":"eyJ2b3VjaGVyIjoiRVhBTVBMRSJ9.9QwZcEXAMPLEvoucherJk4LmN0pQ","existing_token":"7d4c2a91-EXAMPLE-4f0b-9e6d-3a1b5c8e2f70:EQoAeXAMPLEtokenAAAA:Wm9wc2E="}';
    public const DATADOME_COOKIE = 'datadome=FAPPpli4jkT1MZGtgF8ez7mjSz8MrJ9fGRNE51Gj79cq9fXp5xn~F1P5APWjqvJojf4U9dq0R81Xaj2hpYtjle95mkg; Max-Age=31536000; Domain=.example.com; Path=/; Secure; SameSite=Lax';
    public const DATADOME_BROWSER_USER_AGENT = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/156.0.0.0 Safari/537.36';
    public const DATADOME_LIST_PROXY = '203.0.113.10:8080';
    public const HCAPTCHA_TOKEN = 'P1_eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.hadwYXNza2V5xQZ2f3Kq8nV2';
    public const HCAPTCHA_RESP_KEY = 'E0_eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJpZCI6IjA09wB1';
    public const HCAPTCHA_USER_AGENT = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/156.0.0.0 Safari/537.36';
    public const MTCAPTCHA_TOKEN = 'v1(0f3c9e2a,7b1d4c88,MTPublic-KzqLY1cKH,5e0c2a9f61d84b37a0c1e9b2d6f4a873,Qm9ndXNUb2tlbkZvclRlc3RzT25seV9fTm90QVJlYWxNVENhcHRjaGFUb2tlbi1BYkNkRWZHaA**)';
    public const GEETEST_V4_SOLUTION_JSON = '{"captcha_id":"54088bb07d2df3c46b79f80300b0abbe","lot_number":"7c1a4f0e9b2d48a6b3e5f7d9c1a3b5e7","pass_token":"2f6c0e4a8b1d5f9e3c7a0b4d8e2f6a1c5b9d3e7f0a4c8b2e6d1f5a9c3e7b0d4f8","gen_time":"1793001600","captcha_output":"TM-rSt-9GZ_6s_5-dyAo3ZzQxZ3nhs8agozV1dXHM52rdKikcSbx6m9Z_vx1YtexMiouzC-83byvGlGzNTrsCk6EaDdzrugpzIevLISL7vRvwymvNEQ-NUfQM_PoUHAgWIs1w=="}';
    public const YANDEX_TOKEN = 'dD0xNzk0NDE5NTg3O2k9MjAzLjAuMTEzLjI1O0Q9QjQ1RjJBN0MxRTkwRDM2ODU0QzA1M0M3QTFFMkIzQzRENUU2RjcwODE5MkEzQjRDNUQ2RTdGODA5MUEyQjNDNEQ1RTZGNzA4MTt1PTE3OTQ0MTk1ODcxMjM0NTY3ODk7aD01ZjBjMmE5ZTYxZDg0YjM3YTBjMWU5YjJkNmY0YTg3Mw==';
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

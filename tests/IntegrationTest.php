<?php

declare(strict_types=1);

namespace CapSkip\Tests;

use CapSkip\ApiClient;
use CapSkip\CapSkip;
use CapSkip\Exceptions\ApiException;
use CapSkip\Exceptions\NetworkException;
use CapSkip\Exceptions\TimeoutException;
use PHPUnit\Framework\TestCase;

/** End-to-end tests driving the real HTTP layer against a local mock server. */
class IntegrationTest extends TestCase
{
    private const SITEKEY = '6Le-wvkSVVABCPBMRTvw0Q4Muexq1bi0DJwx_mJ-';
    private const TS_SITEKEY = '0x4AAAAAAABUYP0XeMJF0xoy';
    private const URL = 'https://example.com';

    private static MockServer $server;

    public static function setUpBeforeClass(): void
    {
        self::$server = new MockServer();
        self::$server->start();
    }

    public static function tearDownAfterClass(): void
    {
        self::$server->stop();
    }

    private function makeSolver(array $overrides = []): CapSkip
    {
        return new CapSkip(array_merge([
            'apiKey' => 'capskip',
            'host' => self::$server->host,
            'port' => self::$server->port,
            'pollingInterval' => 1,
        ], $overrides));
    }

    private function writeImage(): string
    {
        $file = tempnam(sys_get_temp_dir(), 'capskip') . '.png';
        file_put_contents($file, MockServer::png());

        return $file;
    }

    private function b64(): string
    {
        return base64_encode(MockServer::png());
    }

    public function testNormalFile(): void
    {
        $file = $this->writeImage();
        try {
            $result = $this->makeSolver()->normal($file);
            $this->assertSame(MockServer::CODE, $result['code']);
            $this->assertNotEmpty($result['captchaId']);
        } finally {
            @unlink($file);
        }
    }

    public function testNormalBase64(): void
    {
        $this->assertSame(MockServer::CODE, $this->makeSolver()->normal($this->b64())['code']);
    }

    public function testNormalDataUri(): void
    {
        $result = $this->makeSolver()->normal('data:image/png;base64,' . $this->b64());
        $this->assertSame(MockServer::CODE, $result['code']);
    }

    public function testNormalUrlDownload(): void
    {
        $url = self::$server->baseUrl() . '/image.png';
        $this->assertSame(MockServer::CODE, $this->makeSolver()->normal($url)['code']);
    }

    public function testNormalJsonSubmit(): void
    {
        // json=1 makes in.php return a JSON submit response; the SDK must parse it.
        $result = $this->makeSolver()->normal($this->b64(), ['json' => 1]);
        $this->assertSame(MockServer::CODE, $result['code']);
    }

    public function testRecaptchaV2(): void
    {
        $this->assertSame(MockServer::CODE, $this->makeSolver()->recaptcha(self::SITEKEY, self::URL)['code']);
    }

    public function testRecaptchaV2Invisible(): void
    {
        $result = $this->makeSolver()->recaptcha(self::SITEKEY, self::URL, ['invisible' => 1]);
        $this->assertSame(MockServer::CODE, $result['code']);
    }

    public function testRecaptchaV2Enterprise(): void
    {
        $result = $this->makeSolver()->recaptcha(self::SITEKEY, self::URL, ['enterprise' => 1]);
        $this->assertSame(MockServer::CODE, $result['code']);
    }

    public function testRecaptchaV3(): void
    {
        $result = $this->makeSolver()->recaptcha(self::SITEKEY, self::URL, [
            'version' => 'v3',
            'action' => 'submit',
            'score' => 0.7,
        ]);
        $this->assertSame(MockServer::CODE, $result['code']);
    }

    public function testRecaptchaProxy(): void
    {
        $result = $this->makeSolver()->recaptcha(self::SITEKEY, self::URL, [
            'proxy' => ['type' => 'HTTPS', 'uri' => 'user:pass@1.2.3.4:3128'],
        ]);
        $this->assertSame(MockServer::CODE, $result['code']);
    }

    public function testTurnstile(): void
    {
        $result = $this->makeSolver()->turnstile(self::TS_SITEKEY, self::URL);
        $this->assertSame(MockServer::CODE, $result['code']);
        $this->assertSame(MockServer::USER_AGENT, $result['userAgent']);
    }

    public function testTurnstileChallengePage(): void
    {
        $result = $this->makeSolver()->turnstile(self::TS_SITEKEY, self::URL, [
            'action' => 'managed',
            'data' => 'cdata',
            'pagedata' => 'chlpd',
        ]);
        $this->assertSame(MockServer::CODE, $result['code']);
        $this->assertSame(MockServer::USER_AGENT, $result['userAgent']);
    }

    public function testPollingRetriesThenSolves(): void
    {
        $result = $this->makeSolver()->recaptcha(self::SITEKEY, self::URL . '/slow');
        $this->assertSame(MockServer::CODE, $result['code']);
    }

    public function testPollingThroughEmptyResponses(): void
    {
        // Regression: CapSkip returns an empty body before a result is ready; the
        // SDK must keep polling instead of raising "cannot recognize response".
        $result = $this->makeSolver()->recaptcha(self::SITEKEY, self::URL . '/empty');
        $this->assertSame(MockServer::CODE, $result['code']);
    }

    public function testManualSendAndGetResult(): void
    {
        $solver = $this->makeSolver();
        $cid = $solver->send([
            'method' => 'userrecaptcha',
            'googlekey' => self::SITEKEY,
            'pageurl' => self::URL,
        ]);
        $this->assertNotEmpty($cid);
        $this->assertSame(MockServer::CODE, $solver->getResult($cid));
    }

    public function testTimeout(): void
    {
        $solver = $this->makeSolver(['recaptchaTimeout' => 2]);
        $this->expectException(TimeoutException::class);
        $solver->recaptcha(self::SITEKEY, self::URL . '/never');
    }

    public function testBadApiKey(): void
    {
        $solver = $this->makeSolver(['apiKey' => 'badkey']);
        $this->expectException(ApiException::class);
        $solver->recaptcha(self::SITEKEY, self::URL);
    }

    public function testConnectionRefused(): void
    {
        $solver = new CapSkip(['host' => '127.0.0.1', 'port' => 1, 'defaultTimeout' => 2, 'pollingInterval' => 1]);
        $this->expectException(NetworkException::class);
        $solver->send(['method' => 'userrecaptcha', 'googlekey' => self::SITEKEY, 'pageurl' => self::URL]);
    }

    public function testLowLevelApiClient(): void
    {
        $client = new ApiClient(['host' => self::$server->host, 'port' => self::$server->port]);
        $resp = $client->in_([
            'method' => 'turnstile',
            'key' => 'capskip',
            'sitekey' => self::TS_SITEKEY,
            'pageurl' => self::URL,
        ]);
        $this->assertStringStartsWith('OK|', $resp);

        $polled = $client->res([
            'key' => 'capskip',
            'action' => 'get',
            'id' => substr($resp, 3),
            'json' => 1,
        ]);
        $this->assertStringContainsString(MockServer::CODE, $polled);
    }

    public function testMultipleSolves(): void
    {
        // PHP runs synchronously, so solves happen one after another. Both must
        // still resolve to the mock solution.
        $solver = $this->makeSolver();
        $first = $solver->recaptcha(self::SITEKEY, self::URL);
        $second = $solver->turnstile(self::TS_SITEKEY, self::URL);
        $this->assertSame(MockServer::CODE, $first['code']);
        $this->assertSame(MockServer::CODE, $second['code']);
    }
}

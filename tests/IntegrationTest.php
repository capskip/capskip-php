<?php

declare(strict_types=1);

namespace CapSkip\Tests;

use CapSkip\ApiClient;
use CapSkip\CapSkip;
use CapSkip\Exceptions\ApiException;
use CapSkip\Exceptions\NetworkException;
use CapSkip\Exceptions\TimeoutException;
use CapSkip\Exceptions\ValidationException;
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
    public function testAltchaWithChallengeUrl(): void
    {
        $solver = $this->makeSolver();
        $result = $solver->altcha(self::URL, [
            'challenge_url' => 'https://example.com/captcha/api/altcha/challenge',
        ]);

        $this->assertSame(MockServer::ALTCHA_TOKEN, $result['code']);
        $this->assertSame(MockServer::ALTCHA_TOKEN, $result['token']);
        $this->assertSame(MockServer::ALTCHA_NUMBER, $result['number']);
        $this->assertNotEmpty($result['captchaId']);
    }

    public function testAltchaWithInlineChallengeArray(): void
    {
        // An array has to reach the server as JSON, not as the string "Array",
        // or the server answers ERROR_BAD_PARAMETERS.
        $solver = $this->makeSolver();
        $result = $solver->altcha(self::URL, [
            'challenge_json' => [
                'algorithm' => 'SHA-256',
                'challenge' => '3dd28253be6cc0c54d95f7f98c517e68',
                'salt' => '46d5b1c8871e5152d902ee3f?expires=1893456000',
                'signature' => '4b1cf0e0be0f4e5247e50b0f9a449830',
                'maxnumber' => 1000000,
            ],
        ]);

        $this->assertSame(MockServer::ALTCHA_NUMBER, $result['number']);
    }

    // -- Capy -------------------------------------------------------------

    public function testCapy(): void
    {
        $solver = $this->makeSolver();
        $result = $solver->capy('PUZZLE_Abc1dEFghIJKLM2no34P56q7rStu8v', self::URL);

        $expected = json_decode(MockServer::CAPY_SOLUTION_JSON, true);
        $this->assertSame($expected['captchakey'], $result['captchakey']);
        $this->assertSame($expected['challengekey'], $result['challengekey']);
        $this->assertSame($expected['answer'], $result['answer']);
        $this->assertNotEmpty($result['captchaId']);
    }

    public function testCapyAnswerCrossesTheWireUnchanged(): void
    {
        // The answer is the drag path the widget would have recorded; the target
        // site verifies it against the challenge it issued, so any edit breaks it.
        $solver = $this->makeSolver();
        $result = $solver->capy('PUZZLE_Abc1dEFghIJKLM2no34P56q7rStu8v', self::URL, [
            'api_server' => 'https://jp.api.capy.me/',
        ]);

        $expected = json_decode(MockServer::CAPY_SOLUTION_JSON, true);
        $this->assertSame($expected['answer'], $result['answer']);
    }

    public function testCapyAvatarIsRefusedLocally(): void
    {
        $this->expectException(ValidationException::class);

        $solver = $this->makeSolver();
        $solver->capy('PUZZLE_x', self::URL, ['version' => 'avatar']);
    }

    // -- CaptchaFox --------------------------------------------------------

    public function testCaptchaFox(): void
    {
        $solver = $this->makeSolver();
        $result = $solver->captchafox('sk_xtNxpk6fCdFbxh1_xJeGflSdCE9tn99G', self::URL);

        $this->assertSame(MockServer::CAPTCHAFOX_TOKEN, $result['code']);
        $this->assertSame(MockServer::CAPTCHAFOX_TOKEN, $result['token']);
        $this->assertNotEmpty($result['captchaId']);
    }

    public function testCaptchaFoxReportsTheBrowserUserAgent(): void
    {
        // Not the one sent: CapSkip solves in its own browser, and the token has
        // to be submitted under the UA that minted it.
        $callerUa = 'Mozilla/5.0 (the caller own UA)';
        $solver = $this->makeSolver();
        $result = $solver->captchafox('sk_x', self::URL, ['useragent' => $callerUa]);

        $this->assertSame(MockServer::CAPTCHAFOX_USER_AGENT, $result['userAgent']);
        $this->assertNotSame($callerUa, $result['userAgent']);
    }

    public function testCaptchaFoxWithoutASitekeyIsRefusedLocally(): void
    {
        $this->expectException(ValidationException::class);

        $solver = $this->makeSolver();
        $solver->captchafox('', self::URL);
    }

    // -- Friendly Captcha ---------------------------------------------------

    public function testFriendlyCaptcha(): void
    {
        $solver = $this->makeSolver();
        $result = $solver->friendlyCaptcha('FCMGEMUD2M567T8G', self::URL, ['version' => 'v1']);

        $this->assertSame(MockServer::FRIENDLY_CAPTCHA_TOKEN, $result['code']);
        $this->assertSame(MockServer::FRIENDLY_CAPTCHA_TOKEN, $result['token']);
        $this->assertNotEmpty($result['captchaId']);
    }

    public function testFriendlyCaptchaTokenSurvivesTheWireVerbatim(): void
    {
        // The token carries base64 padding and slashes; form encoding must
        // round-trip them, or the target site rejects a token that looks fine.
        $solver = $this->makeSolver();
        $result = $solver->friendlyCaptcha('FCMGEMUD2M567T8G', self::URL, [
            'module_script' => 'https://cdn.example.com/site.min.js',
        ]);

        $this->assertSame(MockServer::FRIENDLY_CAPTCHA_TOKEN, $result['token']);
        $this->assertStringContainsString('/', $result['token']);
        $this->assertStringContainsString('=', $result['token']);
    }

    public function testFriendlyCaptchaBadVersionIsRefusedLocally(): void
    {
        $this->expectException(ValidationException::class);

        $solver = $this->makeSolver();
        $solver->friendlyCaptcha('FCMGEMUD2M567T8G', self::URL, ['version' => 'v3']);
    }
}

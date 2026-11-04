<?php

declare(strict_types=1);

namespace CapSkip\Tests;

use CapSkip\ApiClient;
use CapSkip\AsyncCapSkip;
use CapSkip\CapSkip;
use CapSkip\Exceptions\ValidationException;
use PHPUnit\Framework\TestCase;

/**
 * Mock client answering as CapSkip's res.php does for hCaptcha with json=1:
 * {status, request: token, respKey, useragent}.
 */
class MockHCaptchaApiClient extends ApiClient
{
    /** @var array<string, mixed>|null */
    public ?array $incomings = null;

    /** @var array<int, array<string, mixed>> */
    public array $polls = [];

    public ?string $respKey;

    public ?string $userAgent;

    /** Leave `respKey` out of the reply altogether. */
    public bool $omitRespKey = false;

    public function __construct(?string $respKey = HCaptchaTest::RESP_KEY, ?string $userAgent = HCaptchaTest::BROWSER_UA)
    {
        parent::__construct();
        $this->respKey = $respKey;
        $this->userAgent = $userAgent;
    }

    public function in_(array $options = []): string
    {
        unset($options['files']);
        $this->incomings = $options;

        return 'OK|123';
    }

    public function res(array $params = []): string
    {
        $this->polls[] = $params;
        $json = $params['json'] ?? null;
        if ($json === 1 || $json === '1') {
            $payload = ['status' => 1, 'request' => HCaptchaTest::TOKEN];
            if (!$this->omitRespKey) {
                $payload['respKey'] = $this->respKey;
            }
            if ($this->userAgent !== null) {
                $payload['useragent'] = $this->userAgent;
            }

            return (string) json_encode($payload);
        }

        return 'OK|' . HCaptchaTest::TOKEN;
    }
}

/** Records the options `solve()` was called with, then solves as normal. */
class HCaptchaSolveSpy extends CapSkip
{
    /** @var array<string, mixed> */
    public array $solveOptions = [];

    public function solve(array $options = []): array
    {
        $this->solveOptions = $options;

        return parent::solve($options);
    }
}

class HCaptchaTest extends TestCase
{
    public const URL = 'https://accounts.hcaptcha.com/demo';
    public const SITEKEY = 'a5f74b19-9e45-40e0-b45d-47ff91b7a6c2';
    public const TOKEN = 'P1_eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.hadwYXNza2V5xQZ2f3Kq8nV2';
    public const RESP_KEY = 'E0_eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJpZCI6IjA09wB1';
    public const RQDATA = 'Wk2qT7bX+fV9rL0pZ3mN8cJ1hYsD6uE4gA5oR2iK/0=';

    /**
     * The UA the browser earned the token with, as CapSkip reports it. Kept
     * apart from the caller's own so a test can tell which one came back.
     */
    public const BROWSER_UA = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 '
        . '(KHTML, like Gecko) Chrome/156.0.0.0 Safari/537.36';

    private const CALLER_UA = 'Mozilla/5.0 (the caller own UA)';

    private CapSkip $solver;

    private MockHCaptchaApiClient $client;

    protected function setUp(): void
    {
        $this->solver = new CapSkip(['apiKey' => 'API_KEY', 'pollingInterval' => 1]);
        $this->client = new MockHCaptchaApiClient();
        $this->solver->apiClient = $this->client;
    }

    /**
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed>
     */
    private function solve(array $options = []): array
    {
        return $this->solver->hcaptcha(self::SITEKEY, self::URL, $options);
    }

    /** @param array<string, mixed> $expected */
    private function assertSent(array $expected): void
    {
        // assertEquals, not assertSame: the alias pass rebuilds the array, so
        // key order differs from the literal while the content matches.
        $this->assertEquals(array_merge($expected, ['key' => 'API_KEY']), $this->client->incomings);
    }

    /** @param array<string, mixed> $options */
    private function assertRefused(array $options, string $sitekey = self::SITEKEY, string $url = self::URL): void
    {
        try {
            $this->solver->hcaptcha($sitekey, $url, $options);
            $this->fail('expected a ValidationException');
        } catch (ValidationException $e) {
            $this->addToAssertionCount(1);
        }
    }

    // -- what goes out ----------------------------------------------------

    public function testBasic(): void
    {
        $result = $this->solve();

        $this->assertSent([
            'method' => 'hcaptcha',
            'sitekey' => self::SITEKEY,
            'pageurl' => self::URL,
        ]);
        $this->assertSame('123', $result['captchaId']);
    }

    public function testPageurlOptionIsAcceptedForUrl(): void
    {
        $this->solver->hcaptcha(self::SITEKEY, '', ['pageurl' => self::URL]);

        $this->assertSame(self::URL, $this->client->incomings['pageurl']);
    }

    public function testUrlOptionIsAcceptedToo(): void
    {
        $this->solver->hcaptcha(self::SITEKEY, '', ['url' => self::URL]);

        $this->assertSame(self::URL, $this->client->incomings['pageurl']);
        $this->assertArrayNotHasKey('url', $this->client->incomings);
    }

    public function testInvisibleIsSentAs1(): void
    {
        foreach ([1, true, '1', 'true', 'TRUE', 'True'] as $value) {
            $this->solve(['invisible' => $value]);
            $this->assertSame(1, $this->client->incomings['invisible'], var_export($value, true));
        }
    }

    public function testInvisibleOffIsNotSent(): void
    {
        // 0 is the server's default; a PHP false must not reach it as "0".
        foreach ([0, false, '0', 'false', 'FALSE'] as $value) {
            $this->solve(['invisible' => $value]);
            $this->assertArrayNotHasKey('invisible', $this->client->incomings, var_export($value, true));
        }
    }

    public function testBadInvisibleIsRefused(): void
    {
        foreach ([2, -1, 'yes', 'invisible', 1.5, [1]] as $value) {
            $this->assertRefused(['invisible' => $value]);
        }
    }

    public function testEnterpriseRqdataIsSentAsData(): void
    {
        $this->solve(['data' => self::RQDATA]);

        $this->assertSame(self::RQDATA, $this->client->incomings['data']);
    }

    public function testUseragentIsForwarded(): void
    {
        $this->solve(['useragent' => self::CALLER_UA]);

        $this->assertSame(self::CALLER_UA, $this->client->incomings['useragent']);
    }

    public function testCamelCaseAndSnakeCaseUseragentAliases(): void
    {
        foreach (['userAgent', 'user_agent'] as $alias) {
            $this->solve([$alias => self::CALLER_UA]);

            $this->assertSame(self::CALLER_UA, $this->client->incomings['useragent']);
            $this->assertArrayNotHasKey($alias, $this->client->incomings);
        }
    }

    public function testUnsetOptionalsAreNotSent(): void
    {
        $this->solve(['invisible' => null, 'data' => null, 'userAgent' => null, 'proxy' => null]);

        $this->assertSent([
            'method' => 'hcaptcha',
            'sitekey' => self::SITEKEY,
            'pageurl' => self::URL,
        ]);
    }

    public function testEmptyOptionalsAreNotSent(): void
    {
        $this->solve(['invisible' => '', 'data' => '', 'userAgent' => '']);

        $this->assertSent([
            'method' => 'hcaptcha',
            'sitekey' => self::SITEKEY,
            'pageurl' => self::URL,
        ]);
    }

    public function testProxy(): void
    {
        $this->solve(['proxy' => ['type' => 'SOCKS5', 'uri' => 'login:password@1.2.3.4:1080']]);

        $this->assertSent([
            'method' => 'hcaptcha',
            'sitekey' => self::SITEKEY,
            'pageurl' => self::URL,
            'proxy' => 'login:password@1.2.3.4:1080',
            'proxytype' => 'SOCKS5',
        ]);
    }

    public function testABareProxyDefaultsToHttp(): void
    {
        $this->solve(['proxy' => '1.2.3.4:8080']);

        $this->assertSame('1.2.3.4:8080', $this->client->incomings['proxy']);
        $this->assertSame('HTTP', $this->client->incomings['proxytype']);
    }

    public function testEverythingTogether(): void
    {
        $this->solve([
            'invisible' => 1,
            'data' => self::RQDATA,
            'userAgent' => self::CALLER_UA,
            'proxy' => ['type' => 'HTTPS', 'uri' => '1.2.3.4:8080'],
            'json' => 1,
        ]);

        $this->assertSent([
            'method' => 'hcaptcha',
            'sitekey' => self::SITEKEY,
            'pageurl' => self::URL,
            'invisible' => 1,
            'data' => self::RQDATA,
            'useragent' => self::CALLER_UA,
            'proxy' => '1.2.3.4:8080',
            'proxytype' => 'HTTPS',
            'json' => 1,
        ]);
    }

    // -- what is refused before it costs a round-trip ---------------------

    public function testMissingSitekeyIsRefused(): void
    {
        $this->assertRefused([], '');
        $this->assertRefused([], '   ');
    }

    public function testMissingPageurlIsRefused(): void
    {
        $this->assertRefused([], self::SITEKEY, '');
        $this->assertRefused([], self::SITEKEY, '   ');
    }

    public function testNonHttpPageurlIsRefused(): void
    {
        foreach (['accounts.hcaptcha.com/demo', 'ftp://example.com/', 'javascript:alert(1)'] as $url) {
            $this->assertRefused([], self::SITEKEY, $url);
        }
    }

    public function testUnknownParameterIsRefused(): void
    {
        // 2Captcha's `domain` is not part of CapSkip's hCaptcha API.
        $this->assertRefused(['domain' => 'js.hcaptcha.com']);
        $this->assertRefused(['enterprise' => 1]);
    }

    public function testSocks4IsRefused(): void
    {
        $this->expectException(ValidationException::class);

        $this->solve(['proxy' => ['type' => 'SOCKS4', 'uri' => '1.2.3.4:1080']]);
    }

    public function testNothingIsSentWhenRefused(): void
    {
        $this->assertRefused([], '');
        $this->assertRefused(['invisible' => 'yes']);
        $this->assertRefused(['domain' => 'js.hcaptcha.com']);

        $this->assertNull($this->client->incomings);
    }

    // -- what comes back --------------------------------------------------

    public function testTokenIsExposed(): void
    {
        $result = $this->solve();

        $this->assertSame(self::TOKEN, $result['token']);
        $this->assertSame(self::TOKEN, $result['code']);
    }

    public function testRespKeyIsExposed(): void
    {
        $result = $this->solve();

        $this->assertSame(self::RESP_KEY, $result['respKey']);
    }

    public function testEmptyRespKeyStaysEmpty(): void
    {
        $this->client = new MockHCaptchaApiClient('');
        $this->solver->apiClient = $this->client;

        $result = $this->solve();

        $this->assertSame('', $result['respKey']);
        $this->assertSame(self::TOKEN, $result['token']);
    }

    public function testRespKeyIsPresentEvenWhenTheReplyHasNone(): void
    {
        $this->client->omitRespKey = true;

        $result = $this->solve();

        $this->assertArrayHasKey('respKey', $result);
        $this->assertSame('', $result['respKey']);
    }

    public function testResultCarriesTheUserAgentThatEarnedTheToken(): void
    {
        $result = $this->solve(['userAgent' => self::CALLER_UA]);

        $this->assertSame(self::BROWSER_UA, $result['userAgent']);
    }

    public function testUserAgentIsAbsentWhenTheSolveReportedNone(): void
    {
        $this->client = new MockHCaptchaApiClient(self::RESP_KEY, null);
        $this->solver->apiClient = $this->client;

        $result = $this->solve();

        $this->assertArrayNotHasKey('userAgent', $result);
    }

    public function testPollsWithJson(): void
    {
        $this->solve();

        $this->assertSame(1, $this->client->polls[0]['json'] ?? null);
    }

    public function testPlainTextAnswerExpandsToo(): void
    {
        $result = CapSkip::applyHCaptchaSolution(['captchaId' => '123', 'code' => self::TOKEN]);

        $this->assertSame(self::TOKEN, $result['token']);
        $this->assertSame('', $result['respKey']);
    }

    public function testUsesTheBrowserTimeout(): void
    {
        $solver = new HCaptchaSolveSpy(['apiKey' => 'API_KEY', 'recaptchaTimeout' => 321]);
        $solver->apiClient = $this->client;
        $solver->hcaptcha(self::SITEKEY, self::URL);

        $this->assertSame(321, $solver->solveOptions['timeout']);
        $this->assertSame(1, $solver->solveOptions['poll_json']);
    }

    public function testAnExplicitTimeoutWins(): void
    {
        $solver = new HCaptchaSolveSpy(['apiKey' => 'API_KEY', 'recaptchaTimeout' => 321]);
        $solver->apiClient = $this->client;
        $solver->hcaptcha(self::SITEKEY, self::URL, ['timeout' => 42]);

        $this->assertSame(42, $solver->solveOptions['timeout']);
    }

    public function testAsyncAliasSolvesToo(): void
    {
        $solver = new AsyncCapSkip(['apiKey' => 'API_KEY', 'pollingInterval' => 1]);
        $solver->apiClient = $this->client;

        $result = $solver->hcaptcha(self::SITEKEY, self::URL, ['invisible' => true]);

        $this->assertSame(self::TOKEN, $result['token']);
        $this->assertSame(self::RESP_KEY, $result['respKey']);
        $this->assertSame(1, $this->client->incomings['invisible']);
    }
}

<?php

declare(strict_types=1);

namespace CapSkip\Tests;

use CapSkip\ApiClient;
use CapSkip\AsyncCapSkip;
use CapSkip\CapSkip;
use CapSkip\Exceptions\ValidationException;
use PHPUnit\Framework\TestCase;

/**
 * Mock client answering as CapSkip's res.php does for MTCaptcha:
 * {status, request: token} with json=1, OK|token without.
 */
class MockMtCaptchaApiClient extends ApiClient
{
    /** @var array<string, mixed>|null */
    public ?array $incomings = null;

    /** @var array<int, array<string, mixed>> */
    public array $polls = [];

    /** @var array<string, mixed>|null */
    public ?array $jsonReply;

    /** @param array<string, mixed>|null $jsonReply */
    public function __construct(?array $jsonReply = null)
    {
        parent::__construct();
        $this->jsonReply = $jsonReply;
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
            return (string) json_encode($this->jsonReply ?? ['status' => 1, 'request' => MtCaptchaTest::TOKEN]);
        }

        return 'OK|' . MtCaptchaTest::TOKEN;
    }
}

/** Records the timeout `waitResult()` was called with, then polls as normal. */
class MtCaptchaWaitSpy extends CapSkip
{
    public ?float $waitTimeout = null;

    public function waitResult(string $id, float $timeout, float $pollingInterval, int $json = 0)
    {
        $this->waitTimeout = $timeout;

        return parent::waitResult($id, $timeout, $pollingInterval, $json);
    }
}

class MtCaptchaTest extends TestCase
{
    public const URL = 'https://2captcha.com/demo/mtcaptcha';
    public const SITEKEY = 'MTPublic-KzqLY1cKH';
    public const TOKEN = 'v1(0f3c9e2a,7b1d4c88,MTPublic-KzqLY1cKH,5e0c2a9f61d84b37a0c1e9b2d6f4a873,'
        . 'Qm9ndXNUb2tlbkZvclRlc3RzT25seV9fTm90QVJlYWxNVENhcHRjaGFUb2tlbi1BYkNkRWZHaA**)';

    private CapSkip $solver;

    private MockMtCaptchaApiClient $client;

    protected function setUp(): void
    {
        $this->solver = new CapSkip(['apiKey' => 'API_KEY', 'pollingInterval' => 1]);
        $this->client = new MockMtCaptchaApiClient();
        $this->solver->apiClient = $this->client;
    }

    /**
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed>
     */
    private function solve(array $options = [], string $sitekey = self::SITEKEY, string $url = self::URL): array
    {
        return $this->solver->mtcaptcha($sitekey, $url, $options);
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
            $this->solver->mtcaptcha($sitekey, $url, $options);
            $this->fail('expected a ValidationException for ' . var_export([$sitekey, $url, $options], true));
        } catch (ValidationException $e) {
            $this->addToAssertionCount(1);
        }
    }

    // -- what goes out ----------------------------------------------------

    public function testBasic(): void
    {
        $result = $this->solve();

        $this->assertSent([
            'method' => 'mt_captcha',
            'sitekey' => self::SITEKEY,
            'pageurl' => self::URL,
        ]);
        $this->assertSame('123', $result['captchaId']);
    }

    public function testEveryOption(): void
    {
        $this->solve([
            'action' => 'login',
            'userAgent' => 'Mozilla/5.0 (caller)',
            'proxy' => ['type' => 'HTTPS', 'uri' => 'login:password@203.0.113.7:3128'],
        ]);

        $this->assertSent([
            'method' => 'mt_captcha',
            'sitekey' => self::SITEKEY,
            'pageurl' => self::URL,
            'action' => 'login',
            'useragent' => 'Mozilla/5.0 (caller)',
            'proxy' => 'login:password@203.0.113.7:3128',
            'proxytype' => 'HTTPS',
        ]);
    }

    public function testJsonIsForwarded(): void
    {
        $this->solve(['json' => 1]);

        $this->assertSame(1, $this->client->incomings['json']);
    }

    public function testPageurlOptionIsAcceptedForUrl(): void
    {
        $this->solver->mtcaptcha(self::SITEKEY, '', ['pageurl' => self::URL]);

        $this->assertSame(self::URL, $this->client->incomings['pageurl']);
    }

    public function testUseragentAliases(): void
    {
        foreach (['userAgent', 'user_agent', 'useragent'] as $alias) {
            $this->solve([$alias => 'Mozilla/5.0 (caller)']);

            $this->assertSame('Mozilla/5.0 (caller)', $this->client->incomings['useragent']);
            if ($alias !== 'useragent') {
                $this->assertArrayNotHasKey($alias, $this->client->incomings);
            }
        }
    }

    public function testSkPrefixAndWhitespaceAreStripped(): void
    {
        // MTCaptcha's getchallenge query names the key sk=MTPublic-...; callers copy it.
        foreach (['sk=' . self::SITEKEY, ' SK=' . self::SITEKEY . ' ', 'Sk= ' . self::SITEKEY, '  ' . self::SITEKEY . "\t"] as $value) {
            $this->solve([], $value);
            $this->assertSame(self::SITEKEY, $this->client->incomings['sitekey'], var_export($value, true));
        }
    }

    public function testUnsetOptionalsAreNotSent(): void
    {
        $this->solve(['action' => null, 'userAgent' => null, 'proxy' => null]);

        $this->assertSent([
            'method' => 'mt_captcha',
            'sitekey' => self::SITEKEY,
            'pageurl' => self::URL,
        ]);
    }

    public function testEmptyOptionalsAreNotSent(): void
    {
        $this->solve(['action' => '', 'userAgent' => '']);

        $this->assertSent([
            'method' => 'mt_captcha',
            'sitekey' => self::SITEKEY,
            'pageurl' => self::URL,
        ]);
    }

    public function testABareProxyDefaultsToHttp(): void
    {
        $this->solve(['proxy' => '203.0.113.7:8080']);

        $this->assertSame('203.0.113.7:8080', $this->client->incomings['proxy']);
        $this->assertSame('HTTP', $this->client->incomings['proxytype']);
    }

    // -- what is refused before it costs a round-trip ---------------------

    public function testMissingSitekeyIsRefused(): void
    {
        $this->assertRefused([], '');
        $this->assertRefused([], '   ');
        $this->assertRefused([], 'sk=');
    }

    public function testMalformedSitekeyIsRefused(): void
    {
        $values = [
            'KzqLY1cKH',
            'MTPublic-',
            'mtpublic-KzqLY1cKH',
            'MTPublic-Kzq LY1',
            'MTPublic-Kzq.LY1',
            'MTPublic-' . str_repeat('a', 56),
            self::SITEKEY . "\n" . 'x',
        ];
        foreach ($values as $value) {
            $this->assertRefused([], $value);
        }
    }

    public function testLongestSitekeyIsAccepted(): void
    {
        $sitekey = 'MTPublic-' . str_repeat('a', 55);
        $this->solve([], $sitekey);

        $this->assertSame($sitekey, $this->client->incomings['sitekey']);
    }

    public function testBadPageurlIsRefused(): void
    {
        foreach (['', '   ', '2captcha.com/demo/mtcaptcha', 'ftp://example.com/', 'https://', 'javascript:alert(1)'] as $url) {
            $this->assertRefused([], self::SITEKEY, $url);
        }
    }

    public function testUnknownParameterIsRefused(): void
    {
        $this->assertRefused(['invisible' => 1]);
        $this->assertRefused(['data' => 'x']);
    }

    public function testSocks4IsRefused(): void
    {
        $this->expectException(ValidationException::class);

        $this->solve(['proxy' => ['type' => 'SOCKS4', 'uri' => '203.0.113.7:1080']]);
    }

    public function testNothingIsSentWhenRefused(): void
    {
        $this->assertRefused([], 'nope');
        $this->assertRefused([], '');
        $this->assertRefused([], self::SITEKEY, 'ftp://example.com/');
        $this->assertRefused(['invisible' => 1]);
        $this->assertRefused(['proxy' => ['type' => 'SOCKS4', 'uri' => '203.0.113.7:1080']]);

        $this->assertNull($this->client->incomings);
    }

    // -- what comes back --------------------------------------------------

    public function testTokenIsCodeAndToken(): void
    {
        $result = $this->solve();

        $this->assertSame(self::TOKEN, $result['code']);
        $this->assertSame(self::TOKEN, $result['token']);
        $this->assertArrayNotHasKey('solution', $result);
    }

    public function testSolutionTokenFillsAnEmptyRequest(): void
    {
        $this->client = new MockMtCaptchaApiClient(['status' => 1, 'request' => '', 'solution' => ['token' => self::TOKEN]]);
        $this->solver->apiClient = $this->client;

        $result = $this->solve();

        $this->assertSame(self::TOKEN, $result['code']);
        $this->assertSame(self::TOKEN, $result['token']);
    }

    public function testPlainTextAnswerExpandsToo(): void
    {
        $result = CapSkip::applyTokenSolution(['captchaId' => '123', 'code' => self::TOKEN]);

        $this->assertSame(self::TOKEN, $result['token']);
    }

    public function testPollsWithJson(): void
    {
        $this->solve();

        $this->assertSame(1, $this->client->polls[0]['json'] ?? null);
    }

    public function testUsesTheDefaultTimeoutNotTheRecaptchaOne(): void
    {
        // No browser runs, so MTCaptcha keeps the default timeout like ALTCHA.
        $solver = new MtCaptchaWaitSpy(['apiKey' => 'API_KEY', 'defaultTimeout' => 77, 'recaptchaTimeout' => 321]);
        $solver->apiClient = $this->client;
        $solver->mtcaptcha(self::SITEKEY, self::URL);

        $this->assertSame(77.0, $solver->waitTimeout);
        $this->assertArrayNotHasKey('timeout', $this->client->incomings);
    }

    public function testAnExplicitTimeoutWins(): void
    {
        $solver = new MtCaptchaWaitSpy(['apiKey' => 'API_KEY', 'defaultTimeout' => 77]);
        $solver->apiClient = $this->client;
        $solver->mtcaptcha(self::SITEKEY, self::URL, ['timeout' => 42]);

        $this->assertSame(42.0, $solver->waitTimeout);
    }

    public function testAsyncAliasSolvesToo(): void
    {
        $solver = new AsyncCapSkip(['apiKey' => 'API_KEY', 'pollingInterval' => 1]);
        $solver->apiClient = $this->client;

        $result = $solver->mtcaptcha(self::SITEKEY, self::URL, ['action' => 'login']);

        $this->assertSame(self::TOKEN, $result['token']);
        $this->assertSame(self::TOKEN, $result['code']);
        $this->assertSame('login', $this->client->incomings['action']);
    }
}

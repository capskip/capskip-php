<?php

declare(strict_types=1);

namespace CapSkip\Tests;

use CapSkip\ApiClient;
use CapSkip\AsyncCapSkip;
use CapSkip\CapSkip;
use CapSkip\Exceptions\ValidationException;
use PHPUnit\Framework\TestCase;

/**
 * Mock client answering as CapSkip's res.php does for a SmartCaptcha token:
 * {status, request: token} with json=1, OK|token without.
 */
class MockYandexApiClient extends ApiClient
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
            return (string) json_encode($this->jsonReply ?? ['status' => 1, 'request' => YandexSmartTest::TOKEN]);
        }

        return 'OK|' . YandexSmartTest::TOKEN;
    }
}

/** Records the timeout `waitResult()` was called with, then polls as normal. */
class YandexWaitSpy extends CapSkip
{
    public ?float $waitTimeout = null;

    public function waitResult(string $id, float $timeout, float $pollingInterval, int $json = 0)
    {
        $this->waitTimeout = $timeout;

        return parent::waitResult($id, $timeout, $pollingInterval, $json);
    }
}

class YandexSmartTest extends TestCase
{
    public const URL = 'https://smartcaptcha.yandexcloud.net/demo';
    public const SITEKEY = 'ysc1_DAo8nFPdNCMHkAwYxIUJFxW5IIJd99BBfeeeee';
    // base64 of t=...;i=203.0.113.25;D=...: shaped like a real token, carrying
    // a documentation address.
    public const TOKEN = 'dD0xNzk0NDE5NTg3O2k9MjAzLjAuMTEzLjI1O0Q9QjQ1RjJBN0MxRTkwRDM2ODU0QzA1M0M3QTFF'
        . 'MkIzQzRENUU2RjcwODE5MkEzQjRDNUQ2RTdGODA5MUEyQjNDNEQ1RTZGNzA4MTt1PTE3OTQ0MTk1'
        . 'ODcxMjM0NTY3ODk7aD01ZjBjMmE5ZTYxZDg0YjM3YTBjMWU5YjJkNmY0YTg3Mw==';

    private CapSkip $solver;

    private MockYandexApiClient $client;

    protected function setUp(): void
    {
        $this->solver = new CapSkip(['apiKey' => 'API_KEY', 'pollingInterval' => 1]);
        $this->client = new MockYandexApiClient();
        $this->solver->apiClient = $this->client;
    }

    /**
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed>
     */
    private function solve(array $options = [], string $sitekey = self::SITEKEY, string $url = self::URL): array
    {
        return $this->solver->yandexSmart($sitekey, $url, $options);
    }

    /** @param array<string, mixed> $expected */
    private function assertSent(array $expected): void
    {
        $this->assertEquals(array_merge($expected, ['key' => 'API_KEY']), $this->client->incomings);
    }

    /** @param array<string, mixed> $options */
    private function assertRefused(array $options, string $sitekey = self::SITEKEY, string $url = self::URL): void
    {
        try {
            $this->solver->yandexSmart($sitekey, $url, $options);
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
            'method' => 'yandex',
            'sitekey' => self::SITEKEY,
            'pageurl' => self::URL,
        ]);
        $this->assertSame('123', $result['captchaId']);
    }

    public function testEveryOption(): void
    {
        $this->solve([
            'userAgent' => 'Mozilla/5.0 (caller)',
            'proxy' => ['type' => 'SOCKS5', 'uri' => 'login:password@203.0.113.7:1080'],
        ]);

        $this->assertSent([
            'method' => 'yandex',
            'sitekey' => self::SITEKEY,
            'pageurl' => self::URL,
            'useragent' => 'Mozilla/5.0 (caller)',
            'proxy' => 'login:password@203.0.113.7:1080',
            'proxytype' => 'SOCKS5',
        ]);
    }

    public function testPageurlOptionIsAcceptedForUrl(): void
    {
        $this->solver->yandexSmart(self::SITEKEY, '', ['pageurl' => self::URL]);

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

    public function testSitekeyIsTrimmed(): void
    {
        $this->solve([], '  ' . self::SITEKEY . "\n");

        $this->assertSame(self::SITEKEY, $this->client->incomings['sitekey']);
    }

    public function testSitekeyWithoutYsc1PrefixIsAccepted(): void
    {
        // Older client keys carry no ysc1_ prefix; the server takes them.
        $this->solve([], 'FEXfAbHQsToo97VidNVk3j4dC74nGW1DgdxjtNB9');

        $this->assertSame('FEXfAbHQsToo97VidNVk3j4dC74nGW1DgdxjtNB9', $this->client->incomings['sitekey']);
    }

    public function testSitekeyLengthBounds(): void
    {
        foreach ([str_repeat('a', 16), str_repeat('a', 120)] as $sitekey) {
            $this->solve([], $sitekey);
            $this->assertSame($sitekey, $this->client->incomings['sitekey']);
        }
    }

    public function testEmptyOptionalsAreNotSent(): void
    {
        $this->solve(['userAgent' => '', 'proxy' => null]);

        $this->assertSent([
            'method' => 'yandex',
            'sitekey' => self::SITEKEY,
            'pageurl' => self::URL,
        ]);
    }

    public function testJsonIsForwarded(): void
    {
        $this->solve(['json' => 1]);

        $this->assertSame(1, $this->client->incomings['json']);
    }

    // -- what is refused before it costs a round-trip ---------------------

    public function testMissingSitekeyIsRefused(): void
    {
        $this->assertRefused([], '');
        $this->assertRefused([], '   ');
    }

    public function testMalformedSitekeyIsRefused(): void
    {
        $values = [
            str_repeat('a', 15),
            str_repeat('a', 121),
            'ysc1_has space in it',
            'ysc1_dotted.key.value',
            'ysc1_" onload="x',
            self::SITEKEY . "\n" . 'x',
        ];
        foreach ($values as $value) {
            $this->assertRefused([], $value);
        }
    }

    public function testBadPageurlIsRefused(): void
    {
        foreach (['', '   ', 'smartcaptcha.yandexcloud.net/demo', 'ftp://example.com/', 'https://', 'javascript:alert(1)'] as $url) {
            $this->assertRefused([], self::SITEKEY, $url);
        }
    }

    public function testUnknownParameterIsRefused(): void
    {
        // Picture tasks (method=base64 with img_type) are not wrapped here.
        $this->assertRefused(['action' => 'login']);
        $this->assertRefused(['img_type' => 'smart_captcha']);
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
        $this->assertRefused(['action' => 'login']);
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
        $this->client = new MockYandexApiClient(['status' => 1, 'request' => '', 'solution' => ['token' => self::TOKEN]]);
        $this->solver->apiClient = $this->client;

        $result = $this->solve();

        $this->assertSame(self::TOKEN, $result['code']);
        $this->assertSame(self::TOKEN, $result['token']);
    }

    public function testPlainTextAnswerExpandsToo(): void
    {
        $result = $this->solver->solve(['method' => 'yandex', 'sitekey' => self::SITEKEY, 'url' => self::URL]);
        $this->assertSame(self::TOKEN, $result['code']);

        $this->assertSame(self::TOKEN, CapSkip::applyTokenSolution($result)['token']);
    }

    public function testPollsWithJson(): void
    {
        $this->solve();

        $this->assertSame(1, $this->client->polls[0]['json'] ?? null);
    }

    public function testUsesTheRecaptchaTimeout(): void
    {
        // The widget runs in a real browser, so it takes the longer timeout.
        $solver = new YandexWaitSpy(['apiKey' => 'API_KEY', 'defaultTimeout' => 77, 'recaptchaTimeout' => 321]);
        $solver->apiClient = $this->client;
        $solver->yandexSmart(self::SITEKEY, self::URL);

        $this->assertSame(321.0, $solver->waitTimeout);
        $this->assertArrayNotHasKey('timeout', $this->client->incomings);
    }

    public function testAnExplicitTimeoutWins(): void
    {
        $solver = new YandexWaitSpy(['apiKey' => 'API_KEY', 'recaptchaTimeout' => 321]);
        $solver->apiClient = $this->client;
        $solver->yandexSmart(self::SITEKEY, self::URL, ['timeout' => 42]);

        $this->assertSame(42.0, $solver->waitTimeout);
    }

    public function testAsyncAliasSolvesToo(): void
    {
        $solver = new AsyncCapSkip(['apiKey' => 'API_KEY', 'pollingInterval' => 1]);
        $solver->apiClient = $this->client;

        $result = $solver->yandexSmart(self::SITEKEY, self::URL, ['userAgent' => 'Mozilla/5.0 (caller)']);

        $this->assertSame(self::TOKEN, $result['token']);
        $this->assertSame(self::TOKEN, $result['code']);
        $this->assertSame('Mozilla/5.0 (caller)', $this->client->incomings['useragent']);
    }
}

<?php

declare(strict_types=1);

namespace CapSkip\Tests;

use CapSkip\ApiClient;
use CapSkip\AsyncCapSkip;
use CapSkip\CapSkip;
use CapSkip\Exceptions\ValidationException;
use PHPUnit\Framework\TestCase;

/** Mock client answering as CapSkip does for a DataDome task. */
class MockDataDomeApiClient extends ApiClient
{
    /** @var array<string, mixed>|null */
    public ?array $incomings = null;

    /** @var array<string, mixed> */
    public array $polled = [];

    public ?string $proxy;

    public function __construct(?string $proxy = DataDomeTest::LIST_PROXY)
    {
        parent::__construct();
        $this->proxy = $proxy;
    }

    public function in_(array $options = []): string
    {
        unset($options['files']);
        $this->incomings = $options;

        return 'OK|123';
    }

    public function res(array $params = []): string
    {
        $this->polled = $params;
        $json = $params['json'] ?? null;
        if ($json === 1 || $json === '1') {
            $reply = ['status' => 1, 'request' => DataDomeTest::COOKIE, 'userAgent' => DataDomeTest::USER_AGENT];
            if ($this->proxy !== null) {
                $reply['proxy'] = $this->proxy;
            }

            return (string) json_encode($reply);
        }

        return 'OK|' . DataDomeTest::COOKIE;
    }
}

/** Records the options `solve()` was called with, then solves as normal. */
class DataDomeSolveSpy extends CapSkip
{
    /** @var array<string, mixed> */
    public array $solveOptions = [];

    public function solve(array $options = []): array
    {
        $this->solveOptions = $options;

        return parent::solve($options);
    }
}

class DataDomeTest extends TestCase
{
    public const PAGE_URL = 'https://www.example.com/';
    public const CAPTCHA_URL = 'https://geo.captcha-delivery.com/captcha/?initialCid=AHrlqAAAAAMA7KBvCr0n4cEAG330MA%3D%3D'
        . '&hash=D013AA612AB2224D03B2318D0F5B19&cid=KzE6_pZBBTHdCdvZkauT9VAshi0wS7~v5_M_MacEtdw8'
        . '&t=fe&referer=https%3A%2F%2Fwww.example.com%2F&s=45225&e=549255a0289eaf17af60822309ab8db4&dm=cd';
    public const INTERSTITIAL_URL = 'https://geo.captcha-delivery.com/interstitial/?initialCid=AHrlqAAAAAMA7KBvCr0n4cEAG330MA%3D%3D'
        . '&hash=D013AA612AB2224D03B2318D0F5B19&cid=KzE6_pZBBTHdCdvZkauT9VAshi0wS7~v5_M_MacEtdw8'
        . '&referer=https%3A%2F%2Fwww.example.com%2F&s=45225&b=1612345&dm=cd';
    public const USER_AGENT = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 '
        . '(KHTML, like Gecko) Chrome/156.0.0.0 Safari/537.36';

    // What CapSkip hands back: the cookie exactly as DataDome set it, the
    // User-Agent it was earned with, and the proxy its own list supplied.
    public const COOKIE = 'datadome=FAPPpli4jkT1MZGtgF8ez7mjSz8MrJ9fGRNE51Gj79cq9fXp5xn~F1P5APWjqvJojf4U9dq0R81Xaj2hpYtjle95mkg; '
        . 'Max-Age=31536000; Domain=.example.com; Path=/; Secure; SameSite=Lax';
    public const COOKIE_PAIR = 'datadome=FAPPpli4jkT1MZGtgF8ez7mjSz8MrJ9fGRNE51Gj79cq9fXp5xn~F1P5APWjqvJojf4U9dq0R81Xaj2hpYtjle95mkg';
    public const LIST_PROXY = '203.0.113.10:8080';

    private CapSkip $solver;

    private MockDataDomeApiClient $client;

    protected function setUp(): void
    {
        $this->solver = new CapSkip(['apiKey' => 'API_KEY', 'pollingInterval' => 1]);
        $this->client = new MockDataDomeApiClient();
        $this->solver->apiClient = $this->client;
    }

    /**
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed>
     */
    private function solve(array $options = [], ?string $captchaUrl = null, ?string $url = null): array
    {
        return $this->solver->dataDome($captchaUrl ?? self::CAPTCHA_URL, $url ?? self::PAGE_URL, $options);
    }

    /** @param array<string, mixed> $expected */
    private function assertSent(array $expected): void
    {
        // assertEquals, not assertSame: the alias pass rebuilds the array, so
        // key order differs from the literal while the content matches.
        $this->assertEquals(array_merge($expected, ['key' => 'API_KEY']), $this->client->incomings);
    }

    // -- what goes out ----------------------------------------------------

    public function testMinimalTask(): void
    {
        $result = $this->solve();

        $this->assertSent([
            'method' => 'datadome',
            'captcha_url' => self::CAPTCHA_URL,
            'pageurl' => self::PAGE_URL,
        ]);
        $this->assertSame('123', $result['captchaId']);
        // Polled as JSON, so userAgent and proxy come back with the cookie.
        $this->assertSame(1, $this->client->polled['json'] ?? null);
    }

    public function testArgumentsFollow2CaptchaOrder(): void
    {
        // 2Captcha's order is captcha_url, pageurl, userAgent, proxy: the two
        // required ones positional, the two optional ones in $options.
        $this->solver->dataDome(self::CAPTCHA_URL, self::PAGE_URL, [
            'userAgent' => self::USER_AGENT,
            'proxy' => ['type' => 'HTTP', 'uri' => 'login:password@1.2.3.4:8080'],
        ]);

        $this->assertSent([
            'method' => 'datadome',
            'captcha_url' => self::CAPTCHA_URL,
            'pageurl' => self::PAGE_URL,
            'useragent' => self::USER_AGENT,
            'proxy' => 'login:password@1.2.3.4:8080',
            'proxytype' => 'HTTP',
        ]);
    }

    public function testMethodNameIsCaseInsensitive(): void
    {
        // PHP method names are case-insensitive, so 2Captcha's `datadome`
        // spelling resolves to the same method.
        $result = $this->solver->datadome(self::CAPTCHA_URL, self::PAGE_URL);

        $this->assertSame('datadome', $this->client->incomings['method']);
        $this->assertSame(self::COOKIE_PAIR, $result['cookie']);
    }

    public function testPageurlOptionStandsInForUrl(): void
    {
        $this->solver->dataDome(self::CAPTCHA_URL, '', ['pageurl' => self::PAGE_URL]);

        $this->assertSame(self::PAGE_URL, $this->client->incomings['pageurl']);
        $this->assertArrayNotHasKey('url', $this->client->incomings);
    }

    public function testCamelCaseCaptchaUrlIsAccepted(): void
    {
        $this->solver->dataDome('', self::PAGE_URL, ['captchaUrl' => self::CAPTCHA_URL]);

        $this->assertSame(self::CAPTCHA_URL, $this->client->incomings['captcha_url']);
        $this->assertArrayNotHasKey('captchaUrl', $this->client->incomings);

        $this->solver->dataDome('', self::PAGE_URL, ['captchaURL' => self::INTERSTITIAL_URL]);

        $this->assertSame(self::INTERSTITIAL_URL, $this->client->incomings['captcha_url']);
    }

    public function testInterstitialDeviceCheck(): void
    {
        $this->solve([], self::INTERSTITIAL_URL);

        $this->assertSame(self::INTERSTITIAL_URL, $this->client->incomings['captcha_url']);
    }

    public function testHtmlEscapedIframeSrcIsAccepted(): void
    {
        // An iframe src copied out of the page source carries &amp;; the
        // server decodes it, so it is not refused here either -- and it goes
        // out exactly as given.
        $escaped = str_replace('&', '&amp;', self::CAPTCHA_URL);
        $this->solve([], $escaped);

        $this->assertSame($escaped, $this->client->incomings['captcha_url']);
    }

    public function testSchemeRelativeCaptchaUrlIsAccepted(): void
    {
        $relative = substr(self::CAPTCHA_URL, strlen('https:'));
        $this->solve([], $relative);

        $this->assertSame($relative, $this->client->incomings['captcha_url']);
    }

    public function testStringProxyDefaultsToHttp(): void
    {
        $this->solve(['proxy' => '1.2.3.4:8080']);

        $this->assertSame('1.2.3.4:8080', $this->client->incomings['proxy']);
        $this->assertSame('HTTP', $this->client->incomings['proxytype']);
    }

    public function testSocks5hProxy(): void
    {
        $this->solve(['proxy' => ['type' => 'SOCKS5H', 'uri' => '1.2.3.4:1080']]);

        $this->assertSame('SOCKS5H', $this->client->incomings['proxytype']);
    }

    public function testEmptyOptionalsMeanNotSent(): void
    {
        // The server treats '' exactly like an absent value.
        $this->solve(['userAgent' => '', 'proxy' => '', 'proxytype' => '']);

        $this->assertSent([
            'method' => 'datadome',
            'captcha_url' => self::CAPTCHA_URL,
            'pageurl' => self::PAGE_URL,
        ]);
    }

    public function testUsesTheBrowserTimeout(): void
    {
        $solver = new DataDomeSolveSpy(['apiKey' => 'API_KEY', 'recaptchaTimeout' => 321]);
        $solver->apiClient = $this->client;
        $solver->dataDome(self::CAPTCHA_URL, self::PAGE_URL);

        $this->assertSame(321, $solver->solveOptions['timeout']);
        $this->assertSame(1, $solver->solveOptions['poll_json']);
    }

    public function testAnExplicitTimeoutWins(): void
    {
        $solver = new DataDomeSolveSpy(['apiKey' => 'API_KEY', 'recaptchaTimeout' => 321]);
        $solver->apiClient = $this->client;
        $solver->dataDome(self::CAPTCHA_URL, self::PAGE_URL, ['timeout' => 42]);

        $this->assertSame(42, $solver->solveOptions['timeout']);
    }

    // -- what comes back --------------------------------------------------

    public function testResultCarriesCookieUserAgentAndListProxy(): void
    {
        $result = $this->solve();

        $this->assertSame(self::COOKIE, $result['code']);
        $this->assertSame(self::COOKIE_PAIR, $result['cookie']);
        $this->assertSame(self::USER_AGENT, $result['userAgent']);
        $this->assertSame(self::LIST_PROXY, $result['proxy']);
    }

    public function testNoProxyFieldWhenCapSkipUsedNone(): void
    {
        $this->client->proxy = null;
        $result = $this->solve();

        $this->assertArrayNotHasKey('proxy', $result);
        $this->assertSame(self::COOKIE_PAIR, $result['cookie']);
    }

    public function testExpansionLeavesANonStringAnswerAlone(): void
    {
        $result = CapSkip::applyDataDomeSolution(['captchaId' => '1', 'code' => ['x' => 1]]);

        $this->assertArrayNotHasKey('cookie', $result);
        $this->assertSame(['x' => 1], $result['code']);
    }

    public function testExpansionOfABareCookie(): void
    {
        $result = CapSkip::applyDataDomeSolution(['captchaId' => '1', 'code' => 'datadome=abc']);

        $this->assertSame('datadome=abc', $result['cookie']);
    }

    // -- refused locally, as the server would refuse them -----------------

    /**
     * @param array<string, mixed> $options
     */
    private function assertRefused(array $options = [], ?string $captchaUrl = null, ?string $url = null): string
    {
        try {
            $this->solve($options, $captchaUrl, $url);
        } catch (ValidationException $e) {
            $this->assertNull($this->client->incomings, 'nothing may be sent');

            return $e->getMessage();
        }
        $this->fail('Expected a ValidationException.');
    }

    public function testMissingCaptchaUrl(): void
    {
        $this->assertStringContainsString('captcha_url', $this->assertRefused([], ''));
    }

    public function testMissingPageurl(): void
    {
        $this->assertStringContainsString('pageurl', $this->assertRefused([], null, ''));
    }

    public function testCaptchaUrlOffDataDome(): void
    {
        $this->assertRefused([], str_replace('captcha-delivery.com', 'evil.example', self::CAPTCHA_URL));
    }

    public function testCaptchaUrlLookalikeHost(): void
    {
        $this->assertRefused([], str_replace('geo.captcha-delivery.com', 'evilcaptcha-delivery.com', self::CAPTCHA_URL));
    }

    public function testCaptchaUrlOverHttp(): void
    {
        $this->assertRefused([], str_replace('https://', 'http://', self::CAPTCHA_URL));
    }

    public function testCaptchaUrlOtherPath(): void
    {
        $this->assertRefused([], str_replace('/captcha/', '/other/', self::CAPTCHA_URL));
    }

    public function testCaptchaUrlWithoutChallengeIds(): void
    {
        $this->assertRefused([], 'https://geo.captcha-delivery.com/captcha/?initialCid=A&hash=B');
    }

    public function testBannedBlockPage(): void
    {
        $message = $this->assertRefused([], str_replace('t=fe', 't=bv', self::CAPTCHA_URL));

        $this->assertStringContainsString('t=bv', $message);
    }

    public function testPageurlNotHttp(): void
    {
        $this->assertRefused([], null, 'ftp://www.example.com/');
    }

    public function testPageurlOnTheChallengeHost(): void
    {
        $this->assertRefused([], null, 'https://geo.captcha-delivery.com/');
    }

    public function testFirefoxUserAgent(): void
    {
        $this->assertRefused([
            'userAgent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:130.0) Gecko/20100101 Firefox/130.0',
        ]);
    }

    public function testMacChromeUserAgent(): void
    {
        $this->assertRefused([
            'userAgent' => str_replace('Windows NT 10.0; Win64; x64', 'Macintosh; Intel Mac OS X 10_15_7', self::USER_AGENT),
        ]);
    }

    public function testEdgeUserAgent(): void
    {
        $this->assertRefused(['userAgent' => self::USER_AGENT . ' Edg/156.0.0.0']);
    }

    public function testAndroidChromeUserAgent(): void
    {
        $this->assertRefused([
            'userAgent' => 'Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 '
                . '(KHTML, like Gecko) Chrome/156.0.0.0 Mobile Safari/537.36',
        ]);
    }

    public function testUserAgentMessageNamesTheRule(): void
    {
        $message = $this->assertRefused(['userAgent' => 'curl/8.0']);

        $this->assertStringContainsString('stock Chrome for Windows', $message);
        $this->assertStringContainsString('same major version', $message);
    }

    public function testSocks4Proxy(): void
    {
        $this->assertRefused(['proxy' => ['type' => 'SOCKS4', 'uri' => '1.2.3.4:1080']]);
    }

    public function testUnknownParameter(): void
    {
        $this->assertRefused(['sitekey' => 'nope']);
    }

    // -- AsyncCapSkip -----------------------------------------------------

    public function testAsyncDataDome(): void
    {
        $solver = new AsyncCapSkip(['apiKey' => 'API_KEY', 'pollingInterval' => 1]);
        $solver->apiClient = $this->client;
        $result = $solver->dataDome(self::CAPTCHA_URL, self::PAGE_URL);

        $this->assertSame('datadome', $this->client->incomings['method']);
        $this->assertSame(self::COOKIE_PAIR, $result['cookie']);
        $this->assertSame(self::USER_AGENT, $result['userAgent']);
        $this->assertSame(self::LIST_PROXY, $result['proxy']);
    }

    public function testAsyncBannedBlockPageIsRefused(): void
    {
        $this->expectException(ValidationException::class);

        $solver = new AsyncCapSkip(['apiKey' => 'API_KEY']);
        $solver->apiClient = $this->client;
        $solver->dataDome(str_replace('t=fe', 't=bv', self::CAPTCHA_URL), self::PAGE_URL);
    }
}

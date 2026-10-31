<?php

declare(strict_types=1);

namespace CapSkip\Tests;

use CapSkip\ApiClient;
use CapSkip\AsyncCapSkip;
use CapSkip\CapSkip;
use CapSkip\Exceptions\ValidationException;
use PHPUnit\Framework\TestCase;

/** Mock client returning a realistic AWS WAF answer (an object, not a token). */
class MockAmazonWafApiClient extends ApiClient
{
    /** @var array<string, mixed> */
    public array $incomings = [];

    /** @var mixed */
    public $request;

    /** @param mixed $request */
    public function __construct($request = null)
    {
        parent::__construct();
        $this->request = $request ?? AmazonWafTest::SOLUTION;
    }

    public function in_(array $options = []): string
    {
        unset($options['files']);
        $this->incomings = $options;

        return 'OK|123';
    }

    public function res(array $params = []): string
    {
        // What CapSkip sends: the bare status/request pair, with the two-value
        // object as `request` -- no createTask `solution`.
        $json = $params['json'] ?? null;
        if ($json === 1 || $json === '1') {
            return (string) json_encode(['status' => 1, 'request' => $this->request]);
        }

        return 'OK|' . json_encode($this->request);
    }
}

/** Records the options `solve()` was called with, then solves as normal. */
class SolveSpyCapSkip extends CapSkip
{
    /** @var array<string, mixed> */
    public array $solveOptions = [];

    public function solve(array $options = []): array
    {
        $this->solveOptions = $options;

        return parent::solve($options);
    }
}

class AmazonWafTest extends TestCase
{
    private const URL = 'https://www.example.com/';
    private const SITEKEY = 'AQIDAHjcYuEXAMPLEghicBgQ/7bFaQZ+m5FKCMDnO+vTbNg96AHDh0IR5vgzHNceHYqZR+GOAAAAfjB8';
    private const IV = 'CgAFRDIjfgAAA5yY';
    private const CONTEXT = 'Rbp3r8fvbuhmrCqpUe8Mh1TLvs8OSKJZiSs2vCF4dt9rxVZI7xYJa2n5k/9r+XaXe7KUIu2I8nXTDHHkDvIOTDc2';
    private const CHALLENGE_JS = 'https://3f2a9c7d41b0.edge.sdk.awswaf.com/3f2a9c7d41b0/8e51d0c7a2f4/challenge.js';
    private const CAPTCHA_JS = 'https://3f2a9c7d41b0.edge.sdk.awswaf.com/3f2a9c7d41b0/8e51d0c7a2f4/captcha.js';
    private const WIDGET_KEY = 'Xr9kEXAMPLE2bWQx1vTq8LmZ0aYc4dHs7nFp3gJt6uKw5eRi9AbCdEfGh=_0_1';
    private const JSAPI_JS = 'https://5b7e0a19c3d8.us-east-1.captcha-sdk.awswaf.com/5b7e0a19c3d8/jsapi.js';

    /**
     * What CapSkip hands back: not a token, but the pair AWS WAF trades for the
     * aws-waf-token cookie on its own voucher endpoint.
     *
     * @var array<string, string>
     */
    public const SOLUTION = [
        'captcha_voucher' => 'eyJ2b3VjaGVyIjoiRVhBTVBMRSJ9.9QwZcEXAMPLEvoucherJk4LmN0pQ',
        'existing_token' => '7d4c2a91-EXAMPLE-4f0b-9e6d-3a1b5c8e2f70:EQoAeXAMPLEtokenAAAA:Wm9wc2E=',
    ];

    private CapSkip $solver;

    private MockAmazonWafApiClient $client;

    protected function setUp(): void
    {
        $this->solver = new CapSkip(['apiKey' => 'API_KEY', 'pollingInterval' => 1]);
        $this->client = new MockAmazonWafApiClient();
        $this->solver->apiClient = $this->client;
    }

    /**
     * A challenge-page solve, with `$options` laid over iv and context.
     *
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed>
     */
    private function solve(array $options = []): array
    {
        return $this->solver->amazonWaf(
            self::SITEKEY,
            self::URL,
            array_merge(['iv' => self::IV, 'context' => self::CONTEXT], $options)
        );
    }

    /** @param array<string, mixed> $expected */
    private function assertSent(array $expected): void
    {
        // assertEquals, not assertSame: the alias pass rebuilds the array, so
        // key order differs from the literal while the content matches.
        $this->assertEquals(
            array_merge($expected, ['key' => 'API_KEY']),
            $this->client->incomings
        );
    }

    // -- what goes out ----------------------------------------------------

    public function testChallengePage(): void
    {
        $result = $this->solve([
            'challenge_script' => self::CHALLENGE_JS,
            'captcha_script' => self::CAPTCHA_JS,
        ]);

        $this->assertSent([
            'method' => 'amazon_waf',
            'sitekey' => self::SITEKEY,
            'iv' => self::IV,
            'context' => self::CONTEXT,
            'pageurl' => self::URL,
            'challenge_script' => self::CHALLENGE_JS,
            'captcha_script' => self::CAPTCHA_JS,
        ]);
        $this->assertSame('123', $result['captchaId']);
    }

    public function testIvAndContextMayBeLeftOut(): void
    {
        // Left out, CapSkip reads them from pageurl at solve time.
        $this->solver->amazonWaf(self::SITEKEY, self::URL);

        $this->assertSent([
            'method' => 'amazon_waf',
            'sitekey' => self::SITEKEY,
            'pageurl' => self::URL,
        ]);
    }

    public function testNullIvAndContextAreNotSent(): void
    {
        // A form body can only carry strings, so null would arrive stringified.
        $this->solver->amazonWaf(self::SITEKEY, self::URL, ['iv' => null, 'context' => null]);

        $this->assertSent([
            'method' => 'amazon_waf',
            'sitekey' => self::SITEKEY,
            'pageurl' => self::URL,
        ]);
    }

    public function testEmptyIvAndContextMeanNotSent(): void
    {
        // The server treats '' exactly like an absent value.
        $this->solver->amazonWaf(self::SITEKEY, self::URL, [
            'iv' => '',
            'context' => '',
            'challenge_script' => '',
        ]);

        $this->assertSent([
            'method' => 'amazon_waf',
            'sitekey' => self::SITEKEY,
            'pageurl' => self::URL,
        ]);
    }

    public function testWidgetPage(): void
    {
        $this->solver->amazonWaf(self::WIDGET_KEY, self::URL, ['jsapiScript' => self::JSAPI_JS]);

        $this->assertSent([
            'method' => 'amazon_waf',
            'sitekey' => self::WIDGET_KEY,
            'pageurl' => self::URL,
            'jsapiScript' => self::JSAPI_JS,
        ]);
    }

    public function testScriptAliases(): void
    {
        // createTask callers arrive with challengeScript / captchaScript, and
        // jsapi_script is the snake-case spelling of the documented jsapiScript.
        $this->solve(['challengeScript' => self::CHALLENGE_JS, 'captchaScript' => self::CAPTCHA_JS]);

        $this->assertSame(self::CHALLENGE_JS, $this->client->incomings['challenge_script']);
        $this->assertSame(self::CAPTCHA_JS, $this->client->incomings['captcha_script']);
        $this->assertArrayNotHasKey('challengeScript', $this->client->incomings);
        $this->assertArrayNotHasKey('captchaScript', $this->client->incomings);

        $this->solver->amazonWaf(self::WIDGET_KEY, self::URL, ['jsapi_script' => self::JSAPI_JS]);

        $this->assertSame(self::JSAPI_JS, $this->client->incomings['jsapiScript']);
        $this->assertArrayNotHasKey('jsapi_script', $this->client->incomings);
    }

    public function testConflictingAliasIsRefused(): void
    {
        $this->expectException(ValidationException::class);

        $this->solve([
            'challenge_script' => self::CHALLENGE_JS,
            'challengeScript' => self::CHALLENGE_JS . '?v=2',
        ]);
    }

    public function testSplitsAProxyArrayIntoProxyAndProxytype(): void
    {
        $this->solve(['proxy' => ['type' => 'SOCKS5H', 'uri' => 'login:pass@1.2.3.4:8080']]);

        $this->assertSame('login:pass@1.2.3.4:8080', $this->client->incomings['proxy']);
        $this->assertSame('SOCKS5H', $this->client->incomings['proxytype']);
    }

    public function testUsesTheBrowserTimeout(): void
    {
        // A browser mints the token the voucher belongs to, so the solve gets
        // recaptchaTimeout like the other browser-backed methods.
        $solver = new SolveSpyCapSkip(['apiKey' => 'API_KEY', 'recaptchaTimeout' => 654]);
        $solver->apiClient = $this->client;
        $solver->amazonWaf(self::SITEKEY, self::URL);

        $this->assertSame(654, $solver->solveOptions['timeout']);
        $this->assertSame(1, $solver->solveOptions['poll_json']);
    }

    public function testAnExplicitTimeoutWins(): void
    {
        $solver = new SolveSpyCapSkip(['apiKey' => 'API_KEY', 'recaptchaTimeout' => 654]);
        $solver->apiClient = $this->client;
        $solver->amazonWaf(self::SITEKEY, self::URL, ['timeout' => 42]);

        $this->assertSame(42, $solver->solveOptions['timeout']);
    }

    public function testScriptWithQueryStringIsAccepted(): void
    {
        $this->solve(['challenge_script' => self::CHALLENGE_JS . '?v=2']);

        $this->assertSame(self::CHALLENGE_JS . '?v=2', $this->client->incomings['challenge_script']);
    }

    public function testExplicitPort443IsAccepted(): void
    {
        $url = 'https://5b7e0a19c3d8.us-east-1.captcha-sdk.awswaf.com:443/5b7e0a19c3d8/jsapi.js';
        $this->solver->amazonWaf(self::WIDGET_KEY, self::URL, ['jsapiScript' => $url]);

        $this->assertSame($url, $this->client->incomings['jsapiScript']);
    }

    // -- what is refused before it costs a round-trip ---------------------

    public function testMissingSitekeyIsRefused(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessageMatches('/sitekey/');

        $this->solver->amazonWaf('', self::URL, ['iv' => self::IV, 'context' => self::CONTEXT]);
    }

    public function testMissingPageurlIsRefused(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessageMatches('/pageurl/');

        $this->solver->amazonWaf(self::SITEKEY, '', ['iv' => self::IV, 'context' => self::CONTEXT]);
    }

    /** @return array<string, array{0: string}> */
    public static function nonHttpPageurls(): array
    {
        return [
            'ftp' => ['ftp://example.com/'],
            'no scheme' => ['example.com'],
        ];
    }

    /** @dataProvider nonHttpPageurls */
    public function testNonHttpPageurlIsRefused(string $url): void
    {
        $this->expectException(ValidationException::class);

        $this->solver->amazonWaf(self::SITEKEY, $url);
    }

    public function testIvWithoutContextIsRefused(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessageMatches('/context/');

        $this->solve(['context' => null]);
    }

    public function testContextWithoutIvIsRefused(): void
    {
        $this->expectException(ValidationException::class);

        $this->solve(['iv' => '']);
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function offAwsScripts(): array
    {
        // CapSkip POSTs the solution to the host the script names, so it refuses
        // any script that is not on awswaf.com.
        $bad = [
            'non-awswaf host' => 'https://evil.example.com/abc/challenge.js',
            'http' => 'http://3f2a9c7d41b0.edge.sdk.awswaf.com/3f2a9c7d41b0/challenge.js',
            'awswaf.com as a prefix' => 'https://awswaf.com.evil.example/3f2a9c7d41b0/challenge.js',
            'credentials' => 'https://user:pw@3f2a9c7d41b0.edge.sdk.awswaf.com/3f2a9c7d41b0/challenge.js',
            'port 8443' => 'https://3f2a9c7d41b0.edge.sdk.awswaf.com:8443/3f2a9c7d41b0/challenge.js',
            'wrong filename' => 'https://3f2a9c7d41b0.edge.sdk.awswaf.com/3f2a9c7d41b0/other.js',
            'no dir segment' => 'https://3f2a9c7d41b0.edge.sdk.awswaf.com/challenge.js',
            'not a url' => 'not a url',
        ];

        $cases = [];
        foreach ($bad as $label => $url) {
            foreach (['challenge_script', 'captcha_script', 'jsapiScript'] as $key) {
                $cases["{$key}: {$label}"] = [$key, $url];
            }
        }

        return $cases;
    }

    /** @dataProvider offAwsScripts */
    public function testOffAwsScriptsAreRefused(string $key, string $url): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessageMatches('/not an AWS WAF integration/');

        $this->solve([$key => $url]);
    }

    public function testUnknownParameterIsRefused(): void
    {
        $this->expectException(ValidationException::class);

        $this->solve(['gt' => 'x']);
    }

    public function testRefusesSocks4RatherThanSilentlyGoingDirect(): void
    {
        $this->expectException(ValidationException::class);

        $this->solve(['proxy' => ['type' => 'SOCKS4', 'uri' => '1.2.3.4:1080']]);
    }

    // -- what comes back --------------------------------------------------

    public function testResultIsExpandedIntoThePair(): void
    {
        $result = $this->solve();

        $this->assertSame(self::SOLUTION['captcha_voucher'], $result['captcha_voucher']);
        $this->assertSame(self::SOLUTION['existing_token'], $result['existing_token']);
    }

    public function testRawCodeIsPreserved(): void
    {
        $result = $this->solve();

        $raw = is_array($result['code']) ? $result['code'] : json_decode($result['code'], true);
        $this->assertSame(self::SOLUTION, $raw);
    }

    public function testAJsonStringAnswerIsExpandedToo(): void
    {
        // Plain-text res.php sends the same object as one line after OK|.
        $result = CapSkip::applyAmazonWafSolution([
            'captchaId' => '123',
            'code' => (string) json_encode(self::SOLUTION),
        ]);

        $this->assertSame(self::SOLUTION['captcha_voucher'], $result['captcha_voucher']);
        $this->assertSame(self::SOLUTION['existing_token'], $result['existing_token']);
    }

    public function testUnparseableAnswerIsLeftUntouched(): void
    {
        $this->client->request = 'not-json-at-all';
        $result = $this->solve();

        $this->assertSame('not-json-at-all', $result['code']);
        $this->assertArrayNotHasKey('captcha_voucher', $result);
        $this->assertArrayNotHasKey('existing_token', $result);
    }

    // -- AsyncCapSkip -----------------------------------------------------

    public function testAsyncChallengePage(): void
    {
        $solver = new AsyncCapSkip(['apiKey' => 'API_KEY', 'pollingInterval' => 1]);
        $solver->apiClient = $this->client;
        $result = $solver->amazonWaf(self::SITEKEY, self::URL, [
            'iv' => self::IV,
            'context' => self::CONTEXT,
            'challenge_script' => self::CHALLENGE_JS,
        ]);

        $this->assertSame('amazon_waf', $this->client->incomings['method']);
        $this->assertSame(self::SOLUTION['captcha_voucher'], $result['captcha_voucher']);
        $this->assertSame(self::SOLUTION['existing_token'], $result['existing_token']);
    }

    public function testAsyncWidgetPage(): void
    {
        $solver = new AsyncCapSkip(['apiKey' => 'API_KEY', 'pollingInterval' => 1]);
        $solver->apiClient = $this->client;
        $result = $solver->amazonWaf(self::WIDGET_KEY, self::URL, ['jsapiScript' => self::JSAPI_JS]);

        $this->assertSame(self::JSAPI_JS, $this->client->incomings['jsapiScript']);
        $this->assertSame(self::SOLUTION['existing_token'], $result['existing_token']);
    }

    public function testAsyncLoneContextIsRefused(): void
    {
        $this->expectException(ValidationException::class);

        $solver = new AsyncCapSkip(['apiKey' => 'API_KEY']);
        $solver->apiClient = $this->client;
        $solver->amazonWaf(self::SITEKEY, self::URL, ['context' => self::CONTEXT]);
    }
}

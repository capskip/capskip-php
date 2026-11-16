<?php

declare(strict_types=1);

namespace CapSkip\Tests;

use CapSkip\ApiClient;
use CapSkip\AsyncCapSkip;
use CapSkip\CapSkip;
use CapSkip\Exceptions\ValidationException;
use PHPUnit\Framework\TestCase;

/**
 * Mock client answering as CapSkip's res.php does for GeeTest v4: `request` is
 * the five values as a JSON string, after OK| or inside the JSON envelope,
 * where the server escapes the inner quotes as ".
 */
class MockGeetestV4ApiClient extends ApiClient
{
    /** @var array<string, mixed>|null */
    public ?array $incomings = null;

    /** @var array<int, array<string, mixed>> */
    public array $polls = [];

    public string $code;

    public ?string $rawJson;

    public function __construct(?string $code = null, ?string $rawJson = null)
    {
        parent::__construct();
        $this->code = $code ?? GeetestV4Test::code();
        $this->rawJson = $rawJson;
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
            if ($this->rawJson !== null) {
                return $this->rawJson;
            }
            $body = (string) json_encode(['status' => 1, 'request' => $this->code], JSON_UNESCAPED_SLASHES);

            return str_replace('\\"', '\\u0022', $body);
        }

        return 'OK|' . $this->code;
    }
}

/** Records the timeout `waitResult()` was called with, then polls as normal. */
class GeetestV4WaitSpy extends CapSkip
{
    public ?float $waitTimeout = null;

    public function waitResult(string $id, float $timeout, float $pollingInterval, int $json = 0)
    {
        $this->waitTimeout = $timeout;

        return parent::waitResult($id, $timeout, $pollingInterval, $json);
    }
}

class GeetestV4Test extends TestCase
{
    public const URL = 'https://gt4.geetest.com/';
    public const CAPTCHA_ID = '54088bb07d2df3c46b79f80300b0abbe';
    public const SOLUTION = [
        'captcha_id' => self::CAPTCHA_ID,
        'lot_number' => '7c1a4f0e9b2d48a6b3e5f7d9c1a3b5e7',
        'pass_token' => '2f6c0e4a8b1d5f9e3c7a0b4d8e2f6a1c5b9d3e7f0a4c8b2e6d1f5a9c3e7b0d4f8',
        'gen_time' => '1793001600',
        'captcha_output' => 'TM-rSt-9GZ_6s_5-dyAo3ZzQxZ3nhs8agozV1dXHM52rdKikcSbx6m9Z_vx1YtexMiou'
            . 'zC-83byvGlGzNTrsCk6EaDdzrugpzIevLISL7vRvwymvNEQ-NUfQM_PoUHAgWIs1w==',
    ];

    private CapSkip $solver;

    private MockGeetestV4ApiClient $client;

    /** The five values as the JSON string the server sends in `request`. */
    public static function code(): string
    {
        return (string) json_encode(self::SOLUTION, JSON_UNESCAPED_SLASHES);
    }

    protected function setUp(): void
    {
        $this->solver = new CapSkip(['apiKey' => 'API_KEY', 'pollingInterval' => 1]);
        $this->useClient(new MockGeetestV4ApiClient());
    }

    private function useClient(MockGeetestV4ApiClient $client): void
    {
        $this->client = $client;
        $this->solver->apiClient = $client;
    }

    /**
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed>
     */
    private function solve(array $options = [], string $captchaId = self::CAPTCHA_ID, string $url = self::URL): array
    {
        return $this->solver->geetestV4($captchaId, $url, $options);
    }

    /** @param array<string, mixed> $expected */
    private function assertSent(array $expected): void
    {
        // assertEquals, not assertSame: the alias pass rebuilds the array, so
        // key order differs from the literal while the content matches.
        $this->assertEquals(array_merge($expected, ['key' => 'API_KEY']), $this->client->incomings);
    }

    /** @param array<string, mixed> $options */
    private function assertRefused(array $options, string $captchaId = self::CAPTCHA_ID, string $url = self::URL): void
    {
        try {
            $this->solver->geetestV4($captchaId, $url, $options);
            $this->fail('expected a ValidationException for ' . var_export([$captchaId, $url, $options], true));
        } catch (ValidationException $e) {
            $this->addToAssertionCount(1);
        }
    }

    // -- what goes out ----------------------------------------------------

    public function testBasic(): void
    {
        $result = $this->solve();

        $this->assertSent([
            'method' => 'geetest_v4',
            'captcha_id' => self::CAPTCHA_ID,
            'pageurl' => self::URL,
        ]);
        $this->assertSame('123', $result['captchaId']);
    }

    public function testEveryOption(): void
    {
        $this->solve([
            'risk_type' => 'slide',
            'proxy' => ['type' => 'HTTPS', 'uri' => 'login:password@203.0.113.7:3128'],
        ]);

        $this->assertSent([
            'method' => 'geetest_v4',
            'captcha_id' => self::CAPTCHA_ID,
            'pageurl' => self::URL,
            'risk_type' => 'slide',
            'proxy' => 'login:password@203.0.113.7:3128',
            'proxytype' => 'HTTPS',
        ]);
    }

    public function testCamelCaseAliases(): void
    {
        // The API documents riskType and also reads captchaId; both are accepted
        // and sent under the canonical names.
        $this->solver->geetestV4('', self::URL, ['captchaId' => self::CAPTCHA_ID, 'riskType' => 'icon']);

        $sent = $this->client->incomings;
        $this->assertSame(self::CAPTCHA_ID, $sent['captcha_id']);
        $this->assertSame('icon', $sent['risk_type']);
        $this->assertArrayNotHasKey('captchaId', $sent);
        $this->assertArrayNotHasKey('riskType', $sent);
    }

    public function testPageurlOptionIsAcceptedForUrl(): void
    {
        $this->solver->geetestV4(self::CAPTCHA_ID, '', ['pageurl' => self::URL]);

        $this->assertSame(self::URL, $this->client->incomings['pageurl']);
    }

    public function testCaptchaIdAndRiskTypeAreTrimmed(): void
    {
        $this->solve(['risk_type' => " slide\n"], '  ' . self::CAPTCHA_ID . ' ');

        $this->assertSame(self::CAPTCHA_ID, $this->client->incomings['captcha_id']);
        $this->assertSame('slide', $this->client->incomings['risk_type']);
    }

    public function testRiskTypeIsFreeText(): void
    {
        // GeeTest adds challenge types, so the SDK passes any value through.
        $this->solve(['risk_type' => 'some_future_type']);

        $this->assertSame('some_future_type', $this->client->incomings['risk_type']);
    }

    public function testEmptyOptionalsAreNotSent(): void
    {
        $this->solve(['risk_type' => '', 'proxy' => null]);
        $this->solve(['riskType' => '   ']);

        $this->assertSent([
            'method' => 'geetest_v4',
            'captcha_id' => self::CAPTCHA_ID,
            'pageurl' => self::URL,
        ]);
    }

    public function testJsonIsForwarded(): void
    {
        $this->solve(['json' => 1]);

        $this->assertSame(1, $this->client->incomings['json']);
    }

    public function testABareProxyDefaultsToHttp(): void
    {
        $this->solve(['proxy' => '203.0.113.7:3128']);

        $this->assertSame('203.0.113.7:3128', $this->client->incomings['proxy']);
        $this->assertSame('HTTP', $this->client->incomings['proxytype']);
    }

    // -- what is refused before it costs a round-trip ---------------------

    public function testMissingCaptchaIdIsRefused(): void
    {
        $this->assertRefused([], '');
        $this->assertRefused([], '   ');
        $this->assertRefused(['captchaId' => ' ']);
        $this->assertRefused(['captcha_id' => 12345]);
    }

    public function testBadPageurlIsRefused(): void
    {
        foreach (['', '   ', 'gt4.geetest.com/', 'ftp://example.com/', 'https://', 'javascript:alert(1)'] as $url) {
            $this->assertRefused([], self::CAPTCHA_ID, $url);
        }
    }

    public function testV3ParametersAreRefused(): void
    {
        $this->assertRefused(['gt' => '81388ea1fc187e0c335c0a8907ff2625']);
        $this->assertRefused(['challenge' => '2e6a5d6e7f1c3b4a9d8e0f1a2b3c4d5e']);
        $this->assertRefused(['api_server' => 'api.geetest.com']);
    }

    public function testUnknownParameterIsRefused(): void
    {
        $this->assertRefused(['userAgent' => 'Mozilla/5.0']);
        $this->assertRefused(['sitekey' => self::CAPTCHA_ID]);
    }

    public function testNonStringRiskTypeIsRefused(): void
    {
        $this->assertRefused(['risk_type' => ['slide']]);
    }

    public function testConflictingAliasesAreRefused(): void
    {
        $this->assertRefused(['captchaId' => 'another-captcha-id']);
        $this->assertRefused(['risk_type' => 'slide', 'riskType' => 'icon']);
    }

    public function testSocks4IsRefused(): void
    {
        $this->expectException(ValidationException::class);

        $this->solve(['proxy' => ['type' => 'SOCKS4', 'uri' => '203.0.113.7:1080']]);
    }

    public function testNothingIsSentWhenRefused(): void
    {
        $this->assertRefused([], '');
        $this->assertRefused([], self::CAPTCHA_ID, 'ftp://example.com/');
        $this->assertRefused(['gt' => '81388ea1fc187e0c335c0a8907ff2625']);
        $this->assertRefused(['proxy' => ['type' => 'SOCKS4', 'uri' => '203.0.113.7:1080']]);

        $this->assertNull($this->client->incomings);
    }

    // -- what comes back --------------------------------------------------

    public function testFiveValuesAreExpanded(): void
    {
        $result = $this->solve();

        $this->assertSame(self::code(), $result['code']);
        foreach (self::SOLUTION as $field => $value) {
            $this->assertSame($value, $result[$field], $field);
        }
        $this->assertArrayNotHasKey('isOffline', $result);
    }

    public function testTheWireCarriesEscapedQuotes(): void
    {
        // The mock writes the inner quotes as the app does; parsing undoes it.
        $body = $this->client->res(['json' => 1]);

        $this->assertStringContainsString('\\u0022captcha_id\\u0022', $body);
        $this->assertStringNotContainsString('\\"', $body);
    }

    public function testPlainTextAnswerIsExpanded(): void
    {
        $result = $this->solver->solve(['method' => 'geetest_v4', 'captcha_id' => self::CAPTCHA_ID, 'url' => self::URL]);
        $this->assertSame(self::code(), $result['code']);

        $expanded = CapSkip::applyGeetestV4Solution($result);
        $this->assertSame(self::SOLUTION['pass_token'], $expanded['pass_token']);
        $this->assertSame(self::code(), $expanded['code']);
    }

    public function testOfflinePassIsReported(): void
    {
        $code = (string) json_encode(array_merge(self::SOLUTION, ['isOffline' => true]));
        $this->useClient(new MockGeetestV4ApiClient($code));

        $result = $this->solve();

        $this->assertTrue($result['isOffline']);
        $this->assertSame(self::SOLUTION['lot_number'], $result['lot_number']);
    }

    public function testObjectRequestIsExpanded(): void
    {
        // Should the server ever put the object itself in `request`, it still parses.
        $this->useClient(new MockGeetestV4ApiClient(null, (string) json_encode(['status' => 1, 'request' => self::SOLUTION])));

        $result = $this->solve();

        $this->assertSame(self::SOLUTION['captcha_output'], $result['captcha_output']);
    }

    public function testUnparsableAnswerIsLeftUntouched(): void
    {
        $this->useClient(new MockGeetestV4ApiClient('not-json'));

        $result = $this->solve();

        $this->assertSame('not-json', $result['code']);
        $this->assertArrayNotHasKey('lot_number', $result);
    }

    public function testPollsWithJson(): void
    {
        $this->solve();

        $this->assertSame(1, $this->client->polls[0]['json'] ?? null);
    }

    public function testUsesTheRecaptchaTimeout(): void
    {
        // A real browser solve, like GeeTest v3, so it takes the longer timeout.
        $solver = new GeetestV4WaitSpy(['apiKey' => 'API_KEY', 'defaultTimeout' => 77, 'recaptchaTimeout' => 321]);
        $solver->apiClient = $this->client;
        $solver->geetestV4(self::CAPTCHA_ID, self::URL);

        $this->assertSame(321.0, $solver->waitTimeout);
        $this->assertArrayNotHasKey('timeout', $this->client->incomings);
    }

    public function testAnExplicitTimeoutWins(): void
    {
        $solver = new GeetestV4WaitSpy(['apiKey' => 'API_KEY', 'recaptchaTimeout' => 321]);
        $solver->apiClient = $this->client;
        $solver->geetestV4(self::CAPTCHA_ID, self::URL, ['timeout' => 42]);

        $this->assertSame(42.0, $solver->waitTimeout);
    }

    public function testAsyncAliasSolvesToo(): void
    {
        $solver = new AsyncCapSkip(['apiKey' => 'API_KEY', 'pollingInterval' => 1]);
        $solver->apiClient = $this->client;

        $result = $solver->geetestV4(self::CAPTCHA_ID, self::URL, ['riskType' => 'icon']);

        $this->assertSame(self::code(), $result['code']);
        $this->assertSame(self::SOLUTION['captcha_output'], $result['captcha_output']);
        $this->assertSame('icon', $this->client->incomings['risk_type']);
    }
}

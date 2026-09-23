<?php

declare(strict_types=1);

namespace CapSkip\Tests;

use CapSkip\ApiClient;
use CapSkip\CapSkip;
use CapSkip\Exceptions\ValidationException;
use PHPUnit\Framework\TestCase;

/** Mock client returning a realistic Friendly Captcha answer. */
class MockFriendlyCaptchaApiClient extends ApiClient
{
    /** @var array<string, mixed> */
    public array $incomings = [];

    public string $token;

    public function __construct(?string $token = null)
    {
        parent::__construct();
        $this->token = $token ?? FriendlyCaptchaTest::V1_TOKEN;
    }

    public function in_(array $options = []): string
    {
        unset($options['files']);
        $this->incomings = $options;

        return 'OK|123';
    }

    public function res(array $params = []): string
    {
        $json = $params['json'] ?? null;
        if ($json === 1 || $json === '1') {
            return (string) json_encode([
                'status' => 1,
                'request' => $this->token,
                'solution' => ['token' => $this->token],
            ]);
        }

        return 'OK|' . $this->token;
    }
}

class FriendlyCaptchaTest extends TestCase
{
    private const URL = 'https://mysite.com/signup';
    private const SITEKEY = 'FCMGEMUD2M567T8G';

    /** A v1 token: four dot-separated parts, a few hundred characters. */
    public const V1_TOKEN = 'c62c4da36bbaf7f253873035832709ef.'
        . 'aqwpWwdbzRWKY/UQAQwwpgAAAAAAAAAAM7hBvJOzqjc=.AAAAAArcCQABAAAAxv8QAAIAAACKYRgA.AgAB';

    private const V1_MODULE_SCRIPT = 'https://cdn.example.com/friendly-challenge@0.9.19/widget.module.min.js';
    private const V2_MODULE_SCRIPT = 'https://cdn.example.com/@friendlycaptcha/sdk@0.1.6/site.min.js';

    private CapSkip $solver;

    private MockFriendlyCaptchaApiClient $client;

    protected function setUp(): void
    {
        $this->solver = new CapSkip(['apiKey' => 'API_KEY', 'pollingInterval' => 1]);
        $this->client = new MockFriendlyCaptchaApiClient();
        $this->solver->apiClient = $this->client;
    }

    /**
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed>
     */
    private function solve(array $options = []): array
    {
        return $this->solver->friendlyCaptcha(self::SITEKEY, self::URL, $options);
    }

    /** @param array<string, mixed> $expected */
    private function assertSent(array $expected): void
    {
        // assertEquals, not assertSame: the alias pass rebuilds the array, so
        // key order differs from the literal below while the content matches.
        $this->assertEquals(
            array_merge($expected, ['key' => 'API_KEY']),
            $this->client->incomings
        );
    }

    // -- what goes out ----------------------------------------------------

    public function testSubmitsTheDocumentedParameters(): void
    {
        $result = $this->solve();

        $this->assertSent([
            'sitekey' => self::SITEKEY,
            'pageurl' => self::URL,
            'method' => 'friendly_captcha',
        ]);
        $this->assertSame('123', $result['captchaId']);
    }

    public function testUsesTheDocumentedUnderscoreMethodSpelling(): void
    {
        // The server accepts friendlycaptcha too, but friendly_captcha is what
        // the parameter table documents and what 2Captcha's own SDKs send.
        $this->solve();

        $this->assertSame('friendly_captcha', $this->client->incomings['method']);
    }

    public function testForwardsAnExplicitVersion(): void
    {
        foreach (['v1', 'v2'] as $version) {
            $this->solve(['version' => $version]);
            $this->assertSame($version, $this->client->incomings['version']);
        }
    }

    public function testAcceptsABareDigitVersion(): void
    {
        // The server takes a bare 1 or 2 as well as v1/v2.
        $this->solve(['version' => 2]);

        $this->assertSame(2, $this->client->incomings['version']);
    }

    public function testForwardsTheWidgetScriptUrls(): void
    {
        // The script URL is the most reliable version signal there is: it is the
        // build the site actually loads.
        $this->solve(['module_script' => self::V2_MODULE_SCRIPT]);
        $this->assertSame(self::V2_MODULE_SCRIPT, $this->client->incomings['module_script']);

        $this->solve([
            'moduleScript' => self::V1_MODULE_SCRIPT,
            'nomoduleScript' => 'https://cdn.example.com/widget.min.js',
        ]);
        $this->assertSame(self::V1_MODULE_SCRIPT, $this->client->incomings['module_script']);
        $this->assertSame(
            'https://cdn.example.com/widget.min.js',
            $this->client->incomings['nomodule_script']
        );
    }

    public function testForwardsTheEuTenant(): void
    {
        // Both tenants mint a token for the same sitekey, so the wrong one is
        // only caught by the target site's own verification.
        $this->solve(['api_server' => 'eu']);

        $this->assertSame('eu', $this->client->incomings['api_server']);
    }

    public function testDoesNotSendAnUnsetOptional(): void
    {
        $this->solve(['version' => null, 'module_script' => null, 'api_server' => null]);

        $this->assertSent([
            'sitekey' => self::SITEKEY,
            'pageurl' => self::URL,
            'method' => 'friendly_captcha',
        ]);
    }

    public function testSplitsAProxyArrayIntoProxyAndProxytype(): void
    {
        $this->solve(['proxy' => ['type' => 'SOCKS5H', 'uri' => 'login:pass@1.2.3.4:8080']]);

        $this->assertSame('login:pass@1.2.3.4:8080', $this->client->incomings['proxy']);
        $this->assertSame('SOCKS5H', $this->client->incomings['proxytype']);
    }

    // -- what is refused before it costs a round-trip ---------------------

    public function testRefusesAnUnknownVersionLocally(): void
    {
        // Solving the wrong version returns a well-formed token the site
        // rejects, so a typo must not reach the server as a silent default.
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessageMatches('/v1/');

        $this->solve(['version' => 'v3']);
    }

    public function testRefusesAMissingSitekey(): void
    {
        $this->expectException(ValidationException::class);

        $this->solver->friendlyCaptcha('', self::URL);
    }

    public function testRefusesAMissingPageurl(): void
    {
        $this->expectException(ValidationException::class);

        $this->solver->friendlyCaptcha(self::SITEKEY, '');
    }

    public function testRefusesAnUnknownParameter(): void
    {
        $this->expectException(ValidationException::class);

        $this->solve(['challenge_url' => 'https://x']);
    }

    public function testRefusesSocks4RatherThanSilentlyGoingDirect(): void
    {
        $this->expectException(ValidationException::class);

        $this->solve(['proxy' => ['type' => 'SOCKS4', 'uri' => '1.2.3.4:1080']]);
    }

    // -- what comes back --------------------------------------------------

    public function testExposesTheToken(): void
    {
        $result = $this->solve();

        $this->assertSame(self::V1_TOKEN, $result['token']);
        $this->assertSame(self::V1_TOKEN, $result['code']);
    }

    public function testPassesTheTokenThroughVerbatim(): void
    {
        // A v1 token's dot-separated parts include base64 padding and slashes;
        // nothing may trim or re-encode them.
        $result = $this->solve();

        $this->assertCount(4, explode('.', $result['token']));
        $this->assertStringContainsString('/', $result['token']);
        $this->assertStringContainsString('=', $result['token']);
    }

    public function testCarriesALargeV2TokenIntact(): void
    {
        // A v2 token is a single opaque string of roughly six kilobytes.
        $v2Token = 'AQQA.' . str_repeat('a', 6000);
        $this->client = new MockFriendlyCaptchaApiClient($v2Token);
        $this->solver->apiClient = $this->client;

        $result = $this->solve(['version' => 'v2']);

        $this->assertSame($v2Token, $result['token']);
        $this->assertSame(strlen($v2Token), strlen($result['token']));
    }

    public function testDoesNotLeakTheSolutionObjectToTheCaller(): void
    {
        $result = $this->solve();

        $this->assertArrayNotHasKey('solution', $result);
    }
}

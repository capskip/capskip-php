<?php

declare(strict_types=1);

namespace CapSkip\Tests;

use CapSkip\ApiClient;
use CapSkip\CapSkip;
use CapSkip\Exceptions\ValidationException;
use PHPUnit\Framework\TestCase;

/** Mock client returning a realistic CaptchaFox answer (a token plus the UA). */
class MockCaptchaFoxApiClient extends ApiClient
{
    /** @var array<string, mixed> */
    public array $incomings = [];

    public ?string $userAgent;

    public function __construct(?string $userAgent = CaptchaFoxTest::BROWSER_UA)
    {
        parent::__construct();
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
        $json = $params['json'] ?? null;
        if ($json === 1 || $json === '1') {
            $payload = [
                'status' => 1,
                'request' => CaptchaFoxTest::TOKEN,
                'solution' => ['token' => CaptchaFoxTest::TOKEN],
            ];
            if ($this->userAgent !== null) {
                $payload['userAgent'] = $this->userAgent;
                $payload['solution']['userAgent'] = $this->userAgent;
            }

            return (string) json_encode($payload);
        }

        return 'OK|' . CaptchaFoxTest::TOKEN;
    }
}

class CaptchaFoxTest extends TestCase
{
    private const URL = 'https://mysite.com/page/with/captchafox';
    private const SITEKEY = 'sk_xtNxpk6fCdFbxh1_xJeGflSdCE9tn99G';

    public const TOKEN = '177f50c25b845601e5c779cdb51b040d523e8ab69efb4d5b343e28df07d05076';

    /**
     * The UA the browser actually minted the token under. CapSkip never applies
     * the caller's own, so these two must stay distinguishable below.
     */
    public const BROWSER_UA = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 '
        . '(KHTML, like Gecko) Chrome/146.0.0.0 Safari/537.36';

    private const CALLER_UA = "Mozilla/5.0 (the caller's own UA)";

    private const MAM_API_SERVER = 'https://s.uicdn.com/mampkg/@mamdev/core.frontend.libs.captchafox/';

    private CapSkip $solver;

    private MockCaptchaFoxApiClient $client;

    protected function setUp(): void
    {
        $this->solver = new CapSkip(['apiKey' => 'API_KEY', 'pollingInterval' => 1]);
        $this->client = new MockCaptchaFoxApiClient();
        $this->solver->apiClient = $this->client;
    }

    /**
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed>
     */
    private function solve(array $options = []): array
    {
        return $this->solver->captchafox(self::SITEKEY, self::URL, $options);
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
            'method' => 'captchafox',
        ]);
        $this->assertSame('123', $result['captchaId']);
    }

    public function testForwardsTheMamWidgetSource(): void
    {
        // The widget source decides the token format: the MAM package returns a
        // MAM_ prefixed token, and sending the wrong one fails silently at the
        // target site rather than erroring here.
        $this->solve(['api_server' => self::MAM_API_SERVER]);

        $this->assertSame(self::MAM_API_SERVER, $this->client->incomings['api_server']);
    }

    public function testAcceptsTheCamelCaseApiServerAlias(): void
    {
        $this->solve(['apiServer' => self::MAM_API_SERVER]);

        $this->assertSame(self::MAM_API_SERVER, $this->client->incomings['api_server']);
    }

    public function testForwardsUseragentAndItsCamelCaseAlias(): void
    {
        $this->solve(['useragent' => self::CALLER_UA]);
        $this->assertSame(self::CALLER_UA, $this->client->incomings['useragent']);

        $this->solve(['userAgent' => self::CALLER_UA]);
        $this->assertSame(self::CALLER_UA, $this->client->incomings['useragent']);
        $this->assertArrayNotHasKey('userAgent', $this->client->incomings);
    }

    public function testDoesNotSendAnUnsetOptional(): void
    {
        $this->solve(['api_server' => null, 'useragent' => null]);

        $this->assertSent([
            'sitekey' => self::SITEKEY,
            'pageurl' => self::URL,
            'method' => 'captchafox',
        ]);
    }

    public function testSplitsAProxyArrayIntoProxyAndProxytype(): void
    {
        $this->solve(['proxy' => ['type' => 'HTTP', 'uri' => 'login:password@1.2.3.4:8080']]);

        $this->assertSame('login:password@1.2.3.4:8080', $this->client->incomings['proxy']);
        $this->assertSame('HTTP', $this->client->incomings['proxytype']);
    }

    public function testDefaultsABareProxyStringToHttp(): void
    {
        $this->solve(['proxy' => '1.2.3.4:8080']);

        $this->assertSame('HTTP', $this->client->incomings['proxytype']);
    }

    // -- what is refused before it costs a round-trip ---------------------

    public function testRefusesAMissingSitekey(): void
    {
        $this->expectException(ValidationException::class);

        $this->solver->captchafox('', self::URL);
    }

    public function testRefusesAMissingPageurl(): void
    {
        $this->expectException(ValidationException::class);

        $this->solver->captchafox(self::SITEKEY, '');
    }

    public function testRefusesAnUnknownParameter(): void
    {
        $this->expectException(ValidationException::class);

        $this->solve(['version' => 'v2']);
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

        $this->assertSame(self::TOKEN, $result['token']);
        $this->assertSame(self::TOKEN, $result['code']);
    }

    public function testReportsTheUserAgentThatMintedTheToken(): void
    {
        // Not the one the caller sent -- CapSkip solves in its own browser, and
        // the token has to be submitted under the UA that produced it.
        $result = $this->solve(['useragent' => self::CALLER_UA]);

        $this->assertSame(self::BROWSER_UA, $result['userAgent']);
        $this->assertNotSame(self::CALLER_UA, $result['userAgent']);
    }

    public function testOmitsTheUserAgentWhenTheSolveReportedNone(): void
    {
        // A UA the solve did not actually use must never be invented.
        $this->client = new MockCaptchaFoxApiClient(null);
        $this->solver->apiClient = $this->client;

        $result = $this->solve();

        $this->assertArrayNotHasKey('userAgent', $result);
        $this->assertSame(self::TOKEN, $result['token']);
    }

    public function testDoesNotLeakTheSolutionObjectToTheCaller(): void
    {
        $result = $this->solve();

        $this->assertArrayNotHasKey('solution', $result);
    }

    public function testReadsTheTokenFromSolutionWhenRequestIsEmpty(): void
    {
        // A client must never be left without the token the poll carried.
        $result = CapSkip::applyTokenSolution([
            'captchaId' => '123',
            'code' => '',
            'solution' => ['token' => self::TOKEN],
        ]);

        $this->assertSame(self::TOKEN, $result['token']);
        $this->assertSame(self::TOKEN, $result['code']);
    }
}

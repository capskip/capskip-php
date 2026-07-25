<?php

declare(strict_types=1);

namespace CapSkip\Tests;

use CapSkip\ApiClient;
use CapSkip\CapSkip;
use CapSkip\Exceptions\ValidationException;
use PHPUnit\Framework\TestCase;

/** Mock client returning a realistic GeeTest v3 answer (JSON string in `request`). */
class MockGeetestApiClient extends ApiClient
{
    /** @var array<string, mixed> */
    public array $incomings = [];

    public string $request;

    public function __construct(?string $request = null)
    {
        parent::__construct();
        $this->request = $request ?? (string) json_encode(GeetestTest::SOLUTION);
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
            return (string) json_encode(['status' => 1, 'request' => $this->request]);
        }

        return 'OK|' . $this->request;
    }
}

class GeetestTest extends TestCase
{
    private const GT = '81388ea1fc187e0c335c0a8907ff2625';
    private const CHALLENGE = '7cf6a8b1a2c34d5e6f7089abcdef0123';
    private const URL = 'https://mysite.com/page/with/geetest';

    public const SOLUTION = [
        'geetest_challenge' => self::CHALLENGE,
        'geetest_validate' => '9b1f4a2c8e7d6b5a4938271605f4e3d2',
        'geetest_seccode' => '9b1f4a2c8e7d6b5a4938271605f4e3d2|jordan',
    ];

    private CapSkip $solver;

    protected function setUp(): void
    {
        $this->solver = new CapSkip(['apiKey' => 'API_KEY', 'pollingInterval' => 1]);
        $this->solver->apiClient = new MockGeetestApiClient();
    }

    /** @param array<string, mixed> $options @return array<string, mixed> */
    private function solve(array $options = []): array
    {
        return $this->solver->geetest(self::GT, self::CHALLENGE, self::URL, $options);
    }

    /** @param array<string, mixed> $expected */
    private function assertSent(array $expected): void
    {
        /** @var MockGeetestApiClient $client */
        $client = $this->solver->apiClient;
        $this->assertEquals(array_merge($expected, ['key' => 'API_KEY']), $client->incomings);
    }

    public function testBasic(): void
    {
        $result = $this->solve();

        $this->assertSent([
            'method' => 'geetest',
            'gt' => self::GT,
            'challenge' => self::CHALLENGE,
            'pageurl' => self::URL,
        ]);
        $this->assertSame('123', $result['captchaId']);
    }

    public function testApiServer(): void
    {
        $this->solve(['api_server' => 'api-na.geetest.com']);

        $this->assertSent([
            'method' => 'geetest',
            'gt' => self::GT,
            'challenge' => self::CHALLENGE,
            'pageurl' => self::URL,
            'api_server' => 'api-na.geetest.com',
        ]);
    }

    public function testApiServerCamelCaseAlias(): void
    {
        $this->solve(['apiServer' => 'api-na.geetest.com']);

        $this->assertSent([
            'method' => 'geetest',
            'gt' => self::GT,
            'challenge' => self::CHALLENGE,
            'pageurl' => self::URL,
            'api_server' => 'api-na.geetest.com',
        ]);
    }

    public function testProxy(): void
    {
        $this->solve(['proxy' => ['type' => 'HTTP', 'uri' => '1.2.3.4:3128']]);

        $this->assertSent([
            'method' => 'geetest',
            'gt' => self::GT,
            'challenge' => self::CHALLENGE,
            'pageurl' => self::URL,
            'proxy' => '1.2.3.4:3128',
            'proxytype' => 'HTTP',
        ]);
    }

    public function testKeepsRawJsonInCode(): void
    {
        // Kept verbatim so callers can forward it to code written against
        // another solver's API.
        $result = $this->solve();

        $this->assertEquals(self::SOLUTION, json_decode($result['code'], true));
    }

    public function testExpandsSolutionFields(): void
    {
        $result = $this->solve();

        $this->assertSame(self::SOLUTION['geetest_challenge'], $result['challenge']);
        $this->assertSame(self::SOLUTION['geetest_validate'], $result['validate']);
        $this->assertSame(self::SOLUTION['geetest_seccode'], $result['seccode']);
    }

    public function testNonJsonCodeIsLeftAlone(): void
    {
        $this->solver->apiClient = new MockGeetestApiClient('not-json');

        $result = $this->solve();

        $this->assertSame('not-json', $result['code']);
        $this->assertArrayNotHasKey('validate', $result);
    }

    public function testMissingChallengeIsRejected(): void
    {
        $this->expectException(ValidationException::class);
        $this->solver->geetest(self::GT, '', self::URL);
    }

    public function testMissingGtIsRejected(): void
    {
        $this->expectException(ValidationException::class);
        $this->solver->geetest('', self::CHALLENGE, self::URL);
    }

    public function testMissingPageurlIsRejected(): void
    {
        // pageurl is documented as required; fail locally rather than paying a
        // round-trip for ERROR_PAGEURL.
        $this->expectException(ValidationException::class);
        $this->solver->geetest(self::GT, self::CHALLENGE, '');
    }

    public function testUnsupportedParameterIsRejected(): void
    {
        $this->expectException(ValidationException::class);
        $this->solve(['sitekey' => 'not-a-geetest-param']);
    }

    /** @dataProvider acceptedProxyTypes */
    public function testAcceptedProxyTypes(string $proxytype): void
    {
        $this->solver->apiClient = new MockGeetestApiClient();
        $this->solve(['proxy' => ['type' => $proxytype, 'uri' => '1.2.3.4:3128']]);

        /** @var MockGeetestApiClient $client */
        $client = $this->solver->apiClient;
        $this->assertSame($proxytype, $client->incomings['proxytype']);
    }

    /** @return array<int, array<int, string>> */
    public function acceptedProxyTypes(): array
    {
        return [['HTTP'], ['HTTPS'], ['SOCKS5'], ['SOCKS5H'], ['socks5h']];
    }

    public function testSocks4IsRejected(): void
    {
        // CapSkip maps only HTTP/HTTPS/SOCKS5/SOCKS5H and answers
        // ERROR_BAD_PARAMETERS for SOCKS4, so fail before the round-trip.
        $this->expectException(ValidationException::class);
        $this->solve(['proxy' => ['type' => 'SOCKS4', 'uri' => '1.2.3.4:3128']]);
    }

    public function testUnknownProxyTypeIsRejected(): void
    {
        $this->expectException(ValidationException::class);
        $this->solve(['proxy' => '1.2.3.4:3128', 'proxytype' => 'FTP']);
    }
}

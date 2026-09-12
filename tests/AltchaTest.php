<?php

declare(strict_types=1);

namespace CapSkip\Tests;

use CapSkip\ApiClient;
use CapSkip\CapSkip;
use CapSkip\Exceptions\ValidationException;
use PHPUnit\Framework\TestCase;

/** Mock client returning a realistic ALTCHA answer (base64 token in `request`). */
class MockAltchaApiClient extends ApiClient
{
    /** @var array<string, mixed> */
    public array $incomings = [];

    public string $request;

    public function __construct(?string $request = null)
    {
        parent::__construct();
        $this->request = $request ?? AltchaTest::token();
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
                'request' => $this->request,
                'solution' => ['token' => $this->request, 'number' => AltchaTest::NUMBER],
            ]);
        }

        return 'OK|' . $this->request;
    }
}

/** Mock client whose poll returns exactly the payload it was given. */
class RawAltchaApiClient extends ApiClient
{
    /** @var array<string, mixed> */
    public array $incomings = [];

    /** @var array<string, mixed> */
    private array $payload;

    /** @param array<string, mixed> $payload */
    public function __construct(array $payload)
    {
        parent::__construct();
        $this->payload = $payload;
    }

    public function in_(array $options = []): string
    {
        unset($options['files']);
        $this->incomings = $options;

        return 'OK|123';
    }

    public function res(array $params = []): string
    {
        return (string) json_encode($this->payload);
    }
}

class AltchaTest extends TestCase
{
    private const URL = 'https://mysite.com/signup';
    private const CHALLENGE_URL = 'https://mysite.com/captcha/api/altcha/challenge';

    public const NUMBER = 9661;

    /** @var array<string, mixed> */
    public const CHALLENGE_DOC = [
        'algorithm' => 'SHA-256',
        'challenge' => '3dd28253be6cc0c54d95f7f98c517e68',
        'salt' => '46d5b1c8871e5152d902ee3f?expires=1893456000',
        'signature' => '4b1cf0e0be0f4e5247e50b0f9a449830',
        'maxnumber' => 1000000,
    ];

    public const V2_NUMBER = 47;

    /**
     * What CapSkip hands back for a *legacy* challenge: base64 of the solved
     * challenge document, with the winning counter in `number`.
     */
    public static function token(): string
    {
        return base64_encode((string) json_encode(
            array_merge(self::CHALLENGE_DOC, ['number' => self::NUMBER])
        ));
    }

    /**
     * A PoW v2 answer is shaped completely differently: no top-level `number`,
     * and the counter sits at `solution.counter`. Captured from a real
     * PBKDF2/SHA-256 deployment (captcha.seventy9.co.uk), the scheme altcha.org
     * documents today.
     */
    public static function v2Token(): string
    {
        return base64_encode((string) json_encode([
            'challenge' => [
                'parameters' => [
                    'algorithm' => 'PBKDF2/SHA-256',
                    'cost' => 50000,
                    'expiresAt' => 1789224090,
                    'keyLength' => 32,
                    'keyPrefix' => '00',
                    'nonce' => '634c4f591fd086beb40d67312b85808a',
                    'salt' => '511e1c75edbf295278c9bfb68191053c',
                ],
                'signature' => '9197e4a35ebff399d669e747c7c5e6ab079b30fe3437df268dc7caf34cf9e281',
            ],
            'solution' => [
                'counter' => self::V2_NUMBER,
                'derivedKey' => '0099db7cb36864d8875ff8305c9a3d2649b1f72cb774de1c',
            ],
        ]));
    }

    public static function challengeJson(): string
    {
        return (string) json_encode(self::CHALLENGE_DOC);
    }

    private CapSkip $solver;

    protected function setUp(): void
    {
        $this->solver = new CapSkip(['apiKey' => 'API_KEY', 'pollingInterval' => 1]);
        $this->solver->apiClient = new MockAltchaApiClient();
    }

    /** @param array<string, mixed> $options @return array<string, mixed> */
    private function solve(array $options = []): array
    {
        return $this->solver->altcha(
            self::URL,
            array_merge(['challenge_url' => self::CHALLENGE_URL], $options)
        );
    }

    /** @param array<string, mixed> $expected */
    private function assertSent(array $expected): void
    {
        /** @var MockAltchaApiClient $client */
        $client = $this->solver->apiClient;
        $this->assertEquals(array_merge($expected, ['key' => 'API_KEY']), $client->incomings);
    }

    public function testBasic(): void
    {
        $result = $this->solve();

        $this->assertSent([
            'method' => 'altcha',
            'pageurl' => self::URL,
            'challenge_url' => self::CHALLENGE_URL,
        ]);
        $this->assertSame('123', $result['captchaId']);
    }

    public function testChallengeJsonString(): void
    {
        $this->solve(['challenge_url' => null, 'challenge_json' => self::challengeJson()]);

        $this->assertSent([
            'method' => 'altcha',
            'pageurl' => self::URL,
            'challenge_json' => self::challengeJson(),
        ]);
    }

    public function testChallengeJsonAcceptsAnArray(): void
    {
        // The form body can only carry a string, so a document passed as an array
        // has to be serialized rather than triggering an array-to-string notice.
        $this->solve(['challenge_url' => null, 'challenge_json' => self::CHALLENGE_DOC]);

        /** @var MockAltchaApiClient $client */
        $client = $this->solver->apiClient;
        $this->assertEquals(
            self::CHALLENGE_DOC,
            json_decode((string) $client->incomings['challenge_json'], true)
        );
    }

    public function testCamelCaseAliases(): void
    {
        $this->solve(['challenge_url' => null, 'challengeUrl' => self::CHALLENGE_URL]);

        $this->assertSent([
            'method' => 'altcha',
            'pageurl' => self::URL,
            'challenge_url' => self::CHALLENGE_URL,
        ]);
    }

    public function testChallengeJsonCamelCaseAlias(): void
    {
        $this->solve(['challenge_url' => null, 'challengeJSON' => self::challengeJson()]);

        $this->assertSent([
            'method' => 'altcha',
            'pageurl' => self::URL,
            'challenge_json' => self::challengeJson(),
        ]);
    }

    public function testBothChallengeParamsAreAllowed(): void
    {
        // CapSkip is deliberately more permissive than 2Captcha here: sending
        // both is not an error, the inline document simply wins.
        $this->solve(['challenge_json' => self::challengeJson()]);

        $this->assertSent([
            'method' => 'altcha',
            'pageurl' => self::URL,
            'challenge_url' => self::CHALLENGE_URL,
            'challenge_json' => self::challengeJson(),
        ]);
    }

    public function testProxy(): void
    {
        $this->solve(['proxy' => ['type' => 'HTTP', 'uri' => '1.2.3.4:3128']]);

        $this->assertSent([
            'method' => 'altcha',
            'pageurl' => self::URL,
            'challenge_url' => self::CHALLENGE_URL,
            'proxy' => '1.2.3.4:3128',
            'proxytype' => 'HTTP',
        ]);
    }

    public function testExposesTokenAndNumber(): void
    {
        $result = $this->solve();

        $this->assertSame(self::token(), $result['code']);
        $this->assertSame(self::token(), $result['token']);
        $this->assertSame(self::NUMBER, $result['number']);
    }

    public function testExposesTheCounterForAProofOfWorkV2Answer(): void
    {
        // A v2 token carries no top-level `number` -- the counter is at
        // `solution.counter`, and the server reports it as `solution.number` in
        // the poll payload. Reading only the token's own `number` silently drops
        // it for every PBKDF2 site, which is the scheme ALTCHA recommends.
        $token = self::v2Token();
        $this->solver->apiClient = new RawAltchaApiClient([
            'status' => 1,
            'request' => $token,
            'solution' => ['token' => $token, 'number' => self::V2_NUMBER],
        ]);

        $result = $this->solve();

        $this->assertSame($token, $result['token']);
        $this->assertSame(self::V2_NUMBER, $result['number']);
    }

    public function testRecoversAV2CounterFromTheTokenWithoutASolution(): void
    {
        $token = self::v2Token();
        $this->solver->apiClient = new RawAltchaApiClient([
            'status' => 1,
            'request' => $token,
        ]);

        $result = $this->solve();

        $this->assertSame(self::V2_NUMBER, $result['number']);
    }

    public function testDoesNotLeakThePollSolutionIntoTheResult(): void
    {
        // Its two fields are already exposed as `token` and `number`.
        $result = $this->solve();

        $this->assertArrayNotHasKey('solution', $result);
    }

    public function testUndecodableAnswerIsLeftAlone(): void
    {
        // No `solution` object either -- a server returning something that is not
        // a token has no counter to report, so there is nothing to fall back on.
        $this->solver->apiClient = new RawAltchaApiClient([
            'status' => 1,
            'request' => 'not-base64-json',
        ]);

        $result = $this->solve();

        $this->assertSame('not-base64-json', $result['code']);
        $this->assertArrayNotHasKey('number', $result);
    }

    public function testTrustsTheServerCounterOverAnUnreadableToken(): void
    {
        // If the two ever disagree, the server worked the answer out and the
        // decode is only an inference from it.
        $this->solver->apiClient = new RawAltchaApiClient([
            'status' => 1,
            'request' => 'not-base64-json',
            'solution' => ['token' => 'not-base64-json', 'number' => 512],
        ]);

        $result = $this->solve();

        $this->assertSame(512, $result['number']);
    }

    public function testUsesTheDefaultTimeoutNotTheRecaptchaOne(): void
    {
        // ALTCHA is CPU proof-of-work measured in milliseconds, not a browser
        // solve, so it must not inherit reCAPTCHA's much longer budget.
        $this->assertNotSame($this->solver->defaultTimeout, $this->solver->recaptchaTimeout);

        $this->solve();

        /** @var MockAltchaApiClient $client */
        $client = $this->solver->apiClient;
        $this->assertArrayNotHasKey('timeout', $client->incomings);
    }

    public function testMissingUrlRaises(): void
    {
        $this->expectException(ValidationException::class);
        $this->solver->altcha('', ['challenge_url' => self::CHALLENGE_URL]);
    }

    public function testMissingBothChallengeParamsRaises(): void
    {
        // CapSkip answers ERROR_BAD_PARAMETERS; fail locally instead of paying
        // for the round-trip.
        $this->expectException(ValidationException::class);
        $this->solver->altcha(self::URL);
    }

    public function testEmptyChallengeParamsRaise(): void
    {
        $this->expectException(ValidationException::class);
        $this->solver->altcha(self::URL, ['challenge_url' => '', 'challenge_json' => '']);
    }

    public function testUnsupportedParameterRaises(): void
    {
        $this->expectException(ValidationException::class);
        $this->solve(['sitekey' => 'not-an-altcha-param']);
    }

    /** @return array<int, array<int, string>> */
    public function proxyTypeProvider(): array
    {
        return [['HTTP'], ['HTTPS'], ['SOCKS5'], ['SOCKS5H'], ['socks5h']];
    }

    /** @dataProvider proxyTypeProvider */
    public function testAcceptedProxyTypes(string $proxytype): void
    {
        $this->solve(['proxy' => ['type' => $proxytype, 'uri' => '1.2.3.4:3128']]);

        /** @var MockAltchaApiClient $client */
        $client = $this->solver->apiClient;
        $this->assertSame($proxytype, $client->incomings['proxytype']);
    }

    public function testSocks4IsRejected(): void
    {
        $this->expectException(ValidationException::class);
        $this->solve(['proxy' => ['type' => 'SOCKS4', 'uri' => '1.2.3.4:3128']]);
    }

    public function testUnknownProxyTypeIsRejected(): void
    {
        $this->expectException(ValidationException::class);
        $this->solve(['proxy' => '1.2.3.4:3128', 'proxytype' => 'FTP']);
    }
}

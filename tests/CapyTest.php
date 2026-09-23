<?php

declare(strict_types=1);

namespace CapSkip\Tests;

use CapSkip\ApiClient;
use CapSkip\CapSkip;
use CapSkip\Exceptions\ValidationException;
use PHPUnit\Framework\TestCase;

/** Mock client returning a realistic Capy answer (an object, not a token). */
class MockCapyApiClient extends ApiClient
{
    /** @var array<string, mixed> */
    public array $incomings = [];

    /** @var mixed */
    public $request;

    /** @param mixed $request */
    public function __construct($request = null)
    {
        parent::__construct();
        $this->request = $request ?? CapyTest::SOLUTION;
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
                'solution' => $this->request,
            ]);
        }

        return 'OK|' . json_encode($this->request);
    }
}

class CapyTest extends TestCase
{
    private const URL = 'https://mysite.com/login';
    private const CAPTCHA_KEY = 'PUZZLE_Abc1dEFghIJKLM2no34P56q7rStu8v';

    /**
     * What CapSkip hands back: not a token, but the three values the Capy widget
     * would have written into the target form, plus respKey for
     * shape-compatibility with 2Captcha's documented response.
     *
     * @var array<string, mixed>
     */
    public const SOLUTION = [
        'captchakey' => self::CAPTCHA_KEY,
        'challengekey' => 'BalY2gJaI8uA2SGVOZhqBQ3V0CYSNNGP',
        'answer' => '0xax8ex0xax84x0xkx7qx0x18x76x0x1ix6sx0x26x68x0x2gx5kx0x34x50x',
        'respKey' => '',
    ];

    private CapSkip $solver;

    private MockCapyApiClient $client;

    protected function setUp(): void
    {
        $this->solver = new CapSkip(['apiKey' => 'API_KEY', 'pollingInterval' => 1]);
        $this->client = new MockCapyApiClient();
        $this->solver->apiClient = $this->client;
    }

    /**
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed>
     */
    private function solve(array $options = []): array
    {
        return $this->solver->capy(self::CAPTCHA_KEY, self::URL, $options);
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
            'captchakey' => self::CAPTCHA_KEY,
            'pageurl' => self::URL,
            'method' => 'capy',
        ]);
        $this->assertSame('123', $result['captchaId']);
    }

    public function testSendsItsSitekeyArgumentAsCaptchakey(): void
    {
        // The SDK argument is the site key, matching every other method; the wire
        // name is `captchakey`, which is what the API documents.
        $this->solve();

        $this->assertSame(self::CAPTCHA_KEY, $this->client->incomings['captchakey']);
        $this->assertArrayNotHasKey('sitekey', $this->client->incomings);
    }

    public function testForwardsApiServer(): void
    {
        $this->solve(['api_server' => 'https://jp.api.capy.me/']);

        $this->assertSame('https://jp.api.capy.me/', $this->client->incomings['api_server']);
    }

    public function testAcceptsTheCamelCaseApiServerAlias(): void
    {
        $this->solve(['apiServer' => 'https://jp.api.capy.me/']);

        $this->assertSame('https://jp.api.capy.me/', $this->client->incomings['api_server']);
    }

    public function testLowercasesUserAgentToTheDocumentedSpelling(): void
    {
        // The server reads both, so the SDK settles on one rather than passing
        // through whichever the caller happened to use.
        $this->solve(['userAgent' => 'Mozilla/5.0']);

        $this->assertSame('Mozilla/5.0', $this->client->incomings['useragent']);
        $this->assertArrayNotHasKey('userAgent', $this->client->incomings);
    }

    public function testAcceptsAnExplicitPuzzleVersion(): void
    {
        $this->solve(['version' => 'puzzle']);

        $this->assertSame('puzzle', $this->client->incomings['version']);
    }

    public function testDoesNotSendAnUnsetOptional(): void
    {
        // A form body can only carry strings, so null would arrive stringified.
        $this->solve(['api_server' => null, 'version' => null]);

        $this->assertSent([
            'captchakey' => self::CAPTCHA_KEY,
            'pageurl' => self::URL,
            'method' => 'capy',
        ]);
    }

    public function testSplitsAProxyArrayIntoProxyAndProxytype(): void
    {
        $this->solve(['proxy' => ['type' => 'SOCKS5', 'uri' => 'login:pass@1.2.3.4:8080']]);

        $this->assertSame('login:pass@1.2.3.4:8080', $this->client->incomings['proxy']);
        $this->assertSame('SOCKS5', $this->client->incomings['proxytype']);
    }

    // -- what is refused before it costs a round-trip ---------------------

    public function testRefusesTheAvatarVersionLocally(): void
    {
        // CapSkip solves the puzzle family only. Returning a puzzle answer for an
        // avatar request would bill for a solve the target site rejects.
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessageMatches('/puzzle/');

        $this->solve(['version' => 'avatar']);
    }

    public function testRefusesAnUnknownVersion(): void
    {
        $this->expectException(ValidationException::class);

        $this->solve(['version' => 'slider']);
    }

    public function testRefusesAMissingCaptchakey(): void
    {
        $this->expectException(ValidationException::class);

        $this->solver->capy('', self::URL);
    }

    public function testRefusesAMissingPageurl(): void
    {
        $this->expectException(ValidationException::class);

        $this->solver->capy(self::CAPTCHA_KEY, '');
    }

    public function testRefusesAnUnknownParameter(): void
    {
        $this->expectException(ValidationException::class);

        $this->solve(['challenge' => 'x']);
    }

    public function testRefusesSocks4RatherThanSilentlyGoingDirect(): void
    {
        $this->expectException(ValidationException::class);

        $this->solve(['proxy' => ['type' => 'SOCKS4', 'uri' => '1.2.3.4:1080']]);
    }

    // -- what comes back --------------------------------------------------

    public function testExpandsTheAnswerIntoTheThreeFormFields(): void
    {
        $result = $this->solve();

        $this->assertSame(self::SOLUTION['captchakey'], $result['captchakey']);
        $this->assertSame(self::SOLUTION['challengekey'], $result['challengekey']);
        $this->assertSame(self::SOLUTION['answer'], $result['answer']);
        $this->assertSame('', $result['respKey']);
    }

    public function testPassesTheAnswerThroughUnchanged(): void
    {
        // The answer is the drag path the widget would have recorded, and the
        // target site verifies it against the challenge it issued.
        $result = $this->solve();

        $this->assertSame(self::SOLUTION['answer'], $result['answer']);
        $this->assertStringNotContainsString(' ', $result['answer']);
    }

    public function testKeepsTheRawAnswerAsCode(): void
    {
        $result = $this->solve();

        $raw = is_array($result['code']) ? $result['code'] : json_decode($result['code'], true);
        $this->assertSame(self::SOLUTION, $raw);
    }

    public function testExpandsAJsonStringAnswerToo(): void
    {
        // `capy()` polls with json=1, where the object arrives already parsed in
        // `request`. A client reading res.php as plain text gets the same object
        // as a single line of JSON after OK|, so the expansion has to read both.
        $result = CapSkip::applyCapySolution([
            'captchaId' => '123',
            'code' => (string) json_encode(self::SOLUTION),
        ]);

        $this->assertSame(self::SOLUTION['challengekey'], $result['challengekey']);
        $this->assertSame(self::SOLUTION['answer'], $result['answer']);
        $this->assertSame(self::SOLUTION['captchakey'], $result['captchakey']);
    }

    public function testDoesNotLeakTheSolutionObjectToTheCaller(): void
    {
        $result = $this->solve();

        $this->assertArrayNotHasKey('solution', $result);
    }

    public function testLeavesAnUnparseableAnswerUntouched(): void
    {
        // A reply the SDK cannot read must reach the caller as the server sent
        // it, rather than being masked by a parse failure.
        $result = CapSkip::applyCapySolution([
            'captchaId' => '123',
            'code' => 'not-json-at-all',
        ]);

        $this->assertSame('not-json-at-all', $result['code']);
        $this->assertArrayNotHasKey('answer', $result);
    }
}

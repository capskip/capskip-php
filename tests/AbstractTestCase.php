<?php

declare(strict_types=1);

namespace CapSkip\Tests;

use CapSkip\CapSkip;
use PHPUnit\Framework\TestCase;

/**
 * Base class for the unit tests. Wires a {@see CapSkip} client up to a mock
 * ApiClient and exposes helpers that assert what was sent and returned.
 */
abstract class AbstractTestCase extends TestCase
{
    protected CapSkip $solver;

    protected function setUp(): void
    {
        $this->solver = new CapSkip(['apiKey' => 'API_KEY', 'pollingInterval' => 1]);
        $this->solver->apiClient = new MockApiClient();
    }

    /**
     * Assert the fields the SDK submitted to `in.php` (the `key` is always added).
     *
     * @param array<string, mixed> $expected
     */
    protected function assertSent(array $expected): void
    {
        /** @var MockApiClient $client */
        $client = $this->solver->apiClient;
        $this->assertEquals(array_merge($expected, ['key' => 'API_KEY']), $client->incomings);
    }

    /**
     * Assert a solve result carries the mock captcha id and solution.
     *
     * @param mixed $result
     */
    protected function assertResult($result): void
    {
        $this->assertIsArray($result);
        $this->assertArrayHasKey('captchaId', $result);
        $this->assertSame(MockApiClient::CAPTCHA_ID, $result['captchaId']);
        $this->assertArrayHasKey('code', $result);
        $this->assertSame(MockApiClient::CODE, $result['code']);
    }
}

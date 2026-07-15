<?php

declare(strict_types=1);

namespace CapSkip\Tests;

class TurnstileTest extends AbstractTestCase
{
    private const SITEKEY = '0x4AAAAAAABUYP0XeMJF0xoy';
    private const URL = 'https://mysite.com/page/with/turnstile';

    public function testBasic(): void
    {
        $result = $this->solver->turnstile(self::SITEKEY, self::URL);

        $this->assertSent([
            'method' => 'turnstile',
            'sitekey' => self::SITEKEY,
            'pageurl' => self::URL,
        ]);
        $this->assertResult($result);
    }

    public function testChallengePage(): void
    {
        $result = $this->solver->turnstile(self::SITEKEY, self::URL, [
            'action' => 'managed',
            'data' => 'cdata_value',
            'pagedata' => 'chlpagedata_value',
        ]);

        $this->assertSent([
            'method' => 'turnstile',
            'sitekey' => self::SITEKEY,
            'pageurl' => self::URL,
            'action' => 'managed',
            'data' => 'cdata_value',
            'pagedata' => 'chlpagedata_value',
        ]);
        $this->assertResult($result);
    }

    public function testProxy(): void
    {
        $result = $this->solver->turnstile(self::SITEKEY, self::URL, [
            'proxy' => ['type' => 'HTTP', 'uri' => '1.2.3.4:3128'],
        ]);

        $this->assertSent([
            'method' => 'turnstile',
            'sitekey' => self::SITEKEY,
            'pageurl' => self::URL,
            'proxy' => '1.2.3.4:3128',
            'proxytype' => 'HTTP',
        ]);
        $this->assertResult($result);
    }

    public function testReturnsUserAgent(): void
    {
        $result = $this->solver->turnstile(self::SITEKEY, self::URL);

        $this->assertSame(MockApiClient::CODE, $result['code']);
        $this->assertSame('TestAgent/1.0', $result['userAgent']);
    }
}

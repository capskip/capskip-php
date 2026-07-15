<?php

declare(strict_types=1);

namespace CapSkip\Tests;

class RecaptchaTest extends AbstractTestCase
{
    private const SITEKEY = '6Le-wvkSVVABCPBMRTvw0Q4Muexq1bi0DJwx_mJ-';
    private const URL = 'https://mysite.com/page/with/recaptcha';

    public function testV2(): void
    {
        $result = $this->solver->recaptcha(self::SITEKEY, self::URL, [
            'invisible' => 1,
            'datas' => 'Crb7VsRAQaBqoaQQtHQQ',
        ]);

        $this->assertSent([
            'method' => 'userrecaptcha',
            'googlekey' => self::SITEKEY,
            'pageurl' => self::URL,
            'invisible' => 1,
            'enterprise' => 0,
            'data-s' => 'Crb7VsRAQaBqoaQQtHQQ',
        ]);
        $this->assertResult($result);
    }

    public function testV2RejectsV3Action(): void
    {
        $this->expectException($this->solver->exceptions);
        $this->solver->recaptcha(self::SITEKEY, 'https://example.com', ['action' => 'verify']);
    }

    public function testV2Enterprise(): void
    {
        $result = $this->solver->recaptcha(self::SITEKEY, self::URL, ['enterprise' => 1]);

        $this->assertSent([
            'method' => 'userrecaptcha',
            'googlekey' => self::SITEKEY,
            'pageurl' => self::URL,
            'enterprise' => 1,
        ]);
        $this->assertResult($result);
    }

    public function testV3(): void
    {
        $result = $this->solver->recaptcha(self::SITEKEY, self::URL, [
            'action' => 'verify',
            'version' => 'v3',
            'score' => 0.7,
        ]);

        $this->assertSent([
            'method' => 'userrecaptcha',
            'googlekey' => self::SITEKEY,
            'pageurl' => self::URL,
            'enterprise' => 0,
            'action' => 'verify',
            'version' => 'v3',
            'min_score' => 0.7,
        ]);
        $this->assertResult($result);
    }

    public function testProxy(): void
    {
        $result = $this->solver->recaptcha(self::SITEKEY, self::URL, [
            'proxy' => ['type' => 'HTTPS', 'uri' => 'login:password@1.2.3.4:3128'],
        ]);

        $this->assertSent([
            'method' => 'userrecaptcha',
            'googlekey' => self::SITEKEY,
            'pageurl' => self::URL,
            'enterprise' => 0,
            'proxy' => 'login:password@1.2.3.4:3128',
            'proxytype' => 'HTTPS',
        ]);
        $this->assertResult($result);
    }
}

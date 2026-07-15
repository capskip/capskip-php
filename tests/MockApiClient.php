<?php

declare(strict_types=1);

namespace CapSkip\Tests;

use CapSkip\ApiClient;

/**
 * Mock ApiClient that records the params it was sent and returns canned
 * responses, mirroring tests/abstract.py from the Python SDK.
 */
class MockApiClient extends ApiClient
{
    public const CAPTCHA_ID = '123';
    public const CODE = 'abcd';

    /** @var array<string, mixed> */
    public array $incomings = [];
    /** @var array<string, mixed> */
    public array $incomingFiles = [];

    public function __construct()
    {
        parent::__construct();
    }

    public function in_(array $options = []): string
    {
        $this->incomingFiles = $options['files'] ?? [];
        unset($options['files']);
        $this->incomings = $options;

        return 'OK|' . self::CAPTCHA_ID;
    }

    public function res(array $params = []): string
    {
        $json = $params['json'] ?? null;
        if ($json === 1 || $json === '1') {
            return '{"status":1,"request":"' . self::CODE . '","useragent":"TestAgent/1.0"}';
        }

        return 'OK|' . self::CODE;
    }
}

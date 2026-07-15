<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use CapSkip\CapSkip;

$solver = new CapSkip([
    'apiKey' => getenv('CAPSKIP_API_KEY') ?: 'capskip',
    'host' => getenv('CAPSKIP_HOST') ?: '127.0.0.1',
    'port' => (int) (getenv('CAPSKIP_PORT') ?: 8080),
]);

// Cloudflare's official Turnstile test key (always passes) and demo page — safe to run as-is.
$sitekey = '1x00000000000000000000AA';
$pageUrl = 'https://demo.turnstile.workers.dev/';

$result = $solver->turnstile($sitekey, $pageUrl);

echo 'Captcha ID: ' . $result['captchaId'] . PHP_EOL;
echo 'Token:      ' . $result['code'] . PHP_EOL;
echo 'User-Agent: ' . ($result['userAgent'] ?? '') . PHP_EOL;

<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use CapSkip\CapSkip;

$solver = new CapSkip([
    'apiKey' => getenv('CAPSKIP_API_KEY') ?: 'capskip',
    'host' => getenv('CAPSKIP_HOST') ?: '127.0.0.1',
    'port' => (int) (getenv('CAPSKIP_PORT') ?: 8080),
]);

// Google's official reCAPTCHA v2 test key and demo page — safe to run as-is.
$sitekey = '6LeIxAcTAAAAAJcZVRqyHh71UMIEGNQ_MXjiZKhI';
$pageUrl = 'https://www.google.com/recaptcha/api2/demo';

$result = $solver->recaptcha($sitekey, $pageUrl);

echo 'Captcha ID: ' . $result['captchaId'] . PHP_EOL;
echo 'Token:      ' . $result['code'] . PHP_EOL;

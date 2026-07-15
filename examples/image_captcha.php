<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use CapSkip\CapSkip;

$solver = new CapSkip([
    'apiKey' => getenv('CAPSKIP_API_KEY') ?: 'capskip',
    'host' => getenv('CAPSKIP_HOST') ?: '127.0.0.1',
    'port' => (int) (getenv('CAPSKIP_PORT') ?: 8080),
]);

// Sample captcha image shipped alongside this script — resolved relative to the
// file so it works no matter which directory you run from.
$image = __DIR__ . '/captcha.png';

$result = $solver->normal($image);

echo 'Captcha ID: ' . $result['captchaId'] . PHP_EOL;
echo 'Solution:   ' . $result['code'] . PHP_EOL;

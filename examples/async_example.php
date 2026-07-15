<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use CapSkip\AsyncCapSkip;
use CapSkip\Exceptions\CapSkipError;

// PHP executes synchronously, so `AsyncCapSkip` is simply an alias of `CapSkip`
// kept for parity with the other CapSkip SDKs. This example solves several
// captcha types one after another and, like the async clients, isolates each
// failure so one error does not stop the rest.

// Official public test keys and demo pages — safe to run as-is.
$recaptchaSitekey = '6LeIxAcTAAAAAJcZVRqyHh71UMIEGNQ_MXjiZKhI'; // Google reCAPTCHA v2 test key
$recaptchaUrl = 'https://www.google.com/recaptcha/api2/demo';
$turnstileSitekey = '1x00000000000000000000AA'; // Cloudflare Turnstile test key (always passes)
$turnstileUrl = 'https://demo.turnstile.workers.dev/';

$solver = new AsyncCapSkip([
    'apiKey' => getenv('CAPSKIP_API_KEY') ?: 'capskip',
    'host' => getenv('CAPSKIP_HOST') ?: '127.0.0.1',
    'port' => (int) (getenv('CAPSKIP_PORT') ?: 8080),
]);

$jobs = [
    'recaptcha v2' => static fn () => $solver->recaptcha($recaptchaSitekey, $recaptchaUrl),
    'recaptcha v3' => static fn () => $solver->recaptcha($recaptchaSitekey, $recaptchaUrl, [
        'version' => 'v3',
        'action' => 'submit',
        'score' => 0.7,
    ]),
    'turnstile' => static fn () => $solver->turnstile($turnstileSitekey, $turnstileUrl),
];

foreach ($jobs as $name => $job) {
    try {
        $result = $job();
        echo "{$name}: " . json_encode($result) . PHP_EOL;
    } catch (CapSkipError $e) {
        echo "{$name}: " . get_class($e) . ' - ' . $e->getMessage() . PHP_EOL;
    }
}

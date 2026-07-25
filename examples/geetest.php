<?php

/**
 * Solve a GeeTest v3 slider.
 *
 * GeeTest v3 needs two values from the target site:
 *
 *   - `gt`        static per site
 *   - `challenge` single-use, expires in about a minute
 *
 * The site fetches them itself from an endpoint that returns
 * `{"gt": "...", "challenge": "..."}` (often `.../register.php` or a
 * `gettype`/`get.php` request). Open DevTools -> Network to find that request,
 * then request a *fresh* pair right before solving, as this example does.
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use CapSkip\CapSkip;

$solver = new CapSkip([
    'apiKey' => getenv('CAPSKIP_API_KEY') ?: 'capskip',
    'host' => getenv('CAPSKIP_HOST') ?: '127.0.0.1',
    'port' => (int) (getenv('CAPSKIP_PORT') ?: 8080),
]);

// A public GeeTest v3 demo page, and the endpoint that page calls to issue a
// fresh gt/challenge pair. Safe to run as-is.
$pageUrl = 'https://2captcha.com/demo/geetest';
$registerUrl = 'https://2captcha.com/api/v1/captcha-demo/gee-test/init-params';

/** Get a fresh gt/challenge pair. Replace with the endpoint your target uses. */
function fetchChallenge(string $url): array
{
    $body = file_get_contents($url);
    if ($body === false) {
        throw new RuntimeException("Could not fetch a gt/challenge pair from {$url}");
    }

    return json_decode($body, true);
}

$pair = fetchChallenge($registerUrl);

$result = $solver->geetest($pair['gt'], $pair['challenge'], $pageUrl);

echo 'Captcha ID: ' . $result['captchaId'] . PHP_EOL;
echo 'Challenge:  ' . $result['challenge'] . PHP_EOL;
echo 'Validate:   ' . $result['validate'] . PHP_EOL;
echo 'Seccode:    ' . $result['seccode'] . PHP_EOL;

// `code` holds the same answer as a raw JSON string, which is what you forward
// if you are porting code written against another solver's API.
echo 'Raw code:   ' . $result['code'] . PHP_EOL;

// Post these back exactly as the site's own front-end would, e.g.:
//
//   http_build_query([
//       'geetest_challenge' => $result['challenge'],
//       'geetest_validate'  => $result['validate'],
//       'geetest_seccode'   => $result['seccode'],
//   ]);
echo 'Form fields: ' . json_encode([
    'geetest_challenge' => $result['challenge'],
    'geetest_validate' => $result['validate'],
    'geetest_seccode' => $result['seccode'],
], JSON_PRETTY_PRINT) . PHP_EOL;

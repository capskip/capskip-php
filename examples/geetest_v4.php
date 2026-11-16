<?php

/**
 * Solve a GeeTest v4 captcha.
 *
 * GeeTest v4 identifies its widget with a single public `captcha_id`. Unlike v3
 * there is no per-request challenge to fetch first: the ID is the same for every
 * visitor, so read it once and reuse it.
 *
 * The page loads `https://static.geetest.com/v4/gt4.js` and passes the ID to
 * `initGeetest4` as `captchaId`:
 *
 *     initGeetest4({ captchaId: "e392e1d7fd421dc63325744d5a2b9c73", product: "bind" }, ...);
 *
 * If the call is built at runtime, open the Network tab and find the request to
 * `gcaptcha4.geetest.com/load`: the ID is its `captcha_id` query parameter.
 *
 * A passed check produces five values, which the site verifies with GeeTest on
 * its server: `captcha_id`, `lot_number`, `pass_token`, `gen_time` and
 * `captcha_output`. CapSkip returns all five.
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use CapSkip\CapSkip;

$solver = new CapSkip([
    'apiKey' => getenv('CAPSKIP_API_KEY') ?: 'capskip',
    'host' => getenv('CAPSKIP_HOST') ?: '127.0.0.1',
    'port' => (int) (getenv('CAPSKIP_PORT') ?: 8080),
]);

// GeeTest's own public v4 demo page and its captcha_id.
const CAPTCHA_ID = '54088bb07d2df3c46b79f80300b0abbe';
const PAGE_URL = 'https://gt4.geetest.com/';

$result = $solver->geetestV4(CAPTCHA_ID, PAGE_URL);

echo 'Captcha ID:     ', $result['captchaId'], PHP_EOL;
echo 'captcha_id:     ', $result['captcha_id'], PHP_EOL;
echo 'lot_number:     ', $result['lot_number'], PHP_EOL;
echo 'pass_token:     ', $result['pass_token'], PHP_EOL;
echo 'gen_time:       ', $result['gen_time'], PHP_EOL;
echo 'captcha_output: ', substr($result['captcha_output'], 0, 40), '...', PHP_EOL;

// --- Optional parameters -------------------------------------------------------
//
//   $result = $solver->geetestV4(CAPTCHA_ID, PAGE_URL, [
//       'risk_type' => 'slide',   // slide, icon, nine, word, phrase, match, winlinze,
//                                 // svg_seed, svg_icon or ai; applied only where the
//                                 // site allows it
//       'proxy' => ['type' => 'HTTP', 'uri' => 'login:password@203.0.113.10:8080'],
//   ]);
//
// --- Submitting the result -----------------------------------------------------
//
// Send the five values under the same names in the request the page's own submit
// makes, unchanged, and solve again for every submission:
//
//   $ch = curl_init($loginUrl);
//   curl_setopt_array($ch, [
//       CURLOPT_RETURNTRANSFER => true,
//       CURLOPT_POSTFIELDS => http_build_query([
//           'captcha_id' => $result['captcha_id'],
//           'lot_number' => $result['lot_number'],
//           'pass_token' => $result['pass_token'],
//           'gen_time' => $result['gen_time'],
//           'captcha_output' => $result['captcha_output'],
//       ]),
//   ]);
//   curl_exec($ch);
//
// If GeeTest itself could not be reached, the result also carries
// $result['isOffline'] === true, and the site decides whether to accept it.

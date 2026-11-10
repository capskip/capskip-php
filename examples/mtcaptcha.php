<?php

/**
 * Solve an MTCaptcha text captcha.
 *
 * MTCaptcha is a text captcha used on login, sign-up and contact forms: the
 * visitor types the distorted characters in a picture, and the widget writes a
 * verified token into a hidden form field named `mtcaptcha-verifiedtoken`.
 * CapSkip takes the sitekey and page address and returns that token.
 *
 * You need two values, both read off the target page:
 *
 *   * sitekey - starts with `MTPublic-`. Most pages set it in a
 *     `mtcaptchaConfig` object in the page source:
 *
 *         var mtcaptchaConfig = { "sitekey": "MTPublic-KzqLY1cKH", "action": "login" };
 *
 *     When the widget is on screen it is also the `sitekey` query parameter of
 *     its iframe on `service.mtcaptcha.com`.
 *   * url - the full URL of the page the widget is on. MTCaptcha issues tokens
 *     only for the domains the sitekey is registered for.
 *
 * If `mtcaptchaConfig` also sets `action`, send that value too.
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use CapSkip\CapSkip;

$solver = new CapSkip([
    'apiKey' => getenv('CAPSKIP_API_KEY') ?: 'capskip',
    'host' => getenv('CAPSKIP_HOST') ?: '127.0.0.1',
    'port' => (int) (getenv('CAPSKIP_PORT') ?: 8080),
]);

// 2Captcha's public MTCaptcha demo page and its sitekey.
const SITEKEY = 'MTPublic-KzqLY1cKH';
const PAGE_URL = 'https://2captcha.com/demo/mtcaptcha';

$result = $solver->mtcaptcha(SITEKEY, PAGE_URL);

echo 'Captcha ID: ', $result['captchaId'], PHP_EOL;
echo 'Token:      ', substr($result['token'], 0, 60), '...', PHP_EOL;

// --- Optional parameters -------------------------------------------------------
//
//   $result = $solver->mtcaptcha(SITEKEY, PAGE_URL, [
//       'action' => 'login',                 // the action mtcaptchaConfig sets
//       'userAgent' => 'Mozilla/5.0 ...',    // the User-Agent to present to MTCaptcha
//       'proxy' => ['type' => 'HTTP', 'uri' => 'login:password@203.0.113.10:8080'],
//   ]);
//
// --- Submitting the token ------------------------------------------------------
//
// Put the token in the form's mtcaptcha-verifiedtoken field and submit the form
// as the page would. Without a browser, post it as that form field:
//
//   $ch = curl_init($formUrl);
//   curl_setopt_array($ch, [
//       CURLOPT_RETURNTRANSFER => true,
//       CURLOPT_POSTFIELDS => http_build_query([
//           'email' => 'someone@example.com',
//           'mtcaptcha-verifiedtoken' => $result['token'],
//       ]),
//   ]);
//   curl_exec($ch);

// `code` holds the same string as `token`, which is what you forward if you are
// porting code written against another solver's API.
assert($result['code'] === $result['token']);

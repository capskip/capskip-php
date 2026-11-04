<?php

/**
 * Solve an hCaptcha widget.
 *
 * hCaptcha is the checkbox widget behind many sign-up, login and checkout forms.
 * CapSkip passes the widget in a real browser for the sitekey and page you send
 * and returns the token the widget would have put in the form. Invisible widgets
 * and hCaptcha Enterprise use the same method.
 *
 * You need two values, both read off the target page:
 *
 *   * sitekey - the public identifier of the widget, the same for every
 *     visitor. Most pages carry it on the container as `data-sitekey`:
 *     `<div class="h-captcha" data-sitekey="...">`. When a page builds the
 *     widget with a script, look for the `sitekey` option of
 *     `hcaptcha.render()`, or the `sitekey=` parameter in the address of the
 *     hCaptcha iframe.
 *   * pageurl - the full URL of the page the widget is on.
 *
 * A widget with `data-size="invisible"` is invisible: pass `'invisible' => 1`.
 * Some hCaptcha Enterprise sites pass an `rqdata` value to the widget through
 * `hcaptcha.setData()` before it runs; send it as `data`. It is issued for one
 * challenge and expires quickly, so read a fresh one for every task.
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use CapSkip\CapSkip;

$solver = new CapSkip([
    'apiKey' => getenv('CAPSKIP_API_KEY') ?: 'capskip',
    'host' => getenv('CAPSKIP_HOST') ?: '127.0.0.1',
    'port' => (int) (getenv('CAPSKIP_PORT') ?: 8080),
]);

// hCaptcha's own public demo page and its sitekey.
const SITEKEY = 'a5f74b19-9e45-40e0-b45d-47ff91b7a6c2';
const PAGE_URL = 'https://accounts.hcaptcha.com/demo';

$result = $solver->hcaptcha(SITEKEY, PAGE_URL);

echo 'Captcha ID: ', $result['captchaId'], PHP_EOL;
echo 'Token:      ', substr($result['token'], 0, 60), '...', PHP_EOL;
echo 'respKey:    ', $result['respKey'] !== '' ? substr($result['respKey'], 0, 40) . '...' : '(empty)', PHP_EOL;
echo 'User-Agent: ', $result['userAgent'] ?? '(none reported)', PHP_EOL;

// --- Invisible and Enterprise widgets -----------------------------------------
//
//   $result = $solver->hcaptcha(SITEKEY, PAGE_URL, [
//       'invisible' => 1,      // data-size="invisible"
//       'data' => $rqdata,     // the rqdata the page passed to hcaptcha.setData()
//   ]);
//
// --- With a per-request proxy -------------------------------------------------
// CapSkip does not force a proxy. At volume, though, hCaptcha scores the network
// a token comes from as well as the browser, so send a proxy with each task (or
// configure a pool in the app: Settings, Captcha types, hCaptcha, Configure
// Proxies). A proxy sent with a task always takes priority over the pool.
//
//   $result = $solver->hcaptcha(SITEKEY, PAGE_URL, [
//       'proxy' => ['type' => 'HTTP', 'uri' => 'login:password@1.2.3.4:8080'],
//   ]);

// --- Submitting the token -----------------------------------------------------
// The widget writes its token into a hidden textarea named h-captcha-response
// (and a copy named g-recaptcha-response). Put the token in both and submit the
// form, or call the widget's data-callback with it. Without a browser, post it
// as those form fields with the User-Agent from the result:
//
//   $ch = curl_init($formUrl);
//   curl_setopt_array($ch, [
//       CURLOPT_RETURNTRANSFER => true,
//       CURLOPT_USERAGENT => $result['userAgent'],
//       CURLOPT_POSTFIELDS => http_build_query([
//           'email' => 'someone@example.com',
//           'h-captcha-response' => $result['token'],
//           'g-recaptcha-response' => $result['token'],
//       ]),
//   ]);
//   curl_exec($ch);
//
// Tokens are single use and expire about two minutes after they are issued, so
// submit each one soon and solve again for the next form submission.
echo 'Form fields: ', json_encode([
    'h-captcha-response' => substr($result['token'], 0, 40) . '...',
    'g-recaptcha-response' => substr($result['token'], 0, 40) . '...',
]), PHP_EOL;

// `code` holds the same string as `token`, which is what you forward if you are
// porting code written against another solver's API.
assert($result['code'] === $result['token']);

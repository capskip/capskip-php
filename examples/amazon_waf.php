<?php

/**
 * Solve an AWS WAF CAPTCHA.
 *
 * AWS WAF shows its CAPTCHA in one of two ways, and they take different inputs:
 *
 *   * A challenge page - the HTTP 405 page that sets `window.gokuProps`. View
 *     its source and read:
 *       sitekey          - gokuProps.key
 *       iv, context      - gokuProps.iv and gokuProps.context (optional, but
 *                          both or neither)
 *       challenge_script - full src of the page's challenge.js
 *       captcha_script   - full src of the page's captcha.js
 *
 *   * A widget page - a form that calls AwsWafCaptcha.renderCaptcha(...). Read:
 *       sitekey          - the `apiKey` passed to renderCaptcha
 *       jsapiScript      - full src of jsapi.js
 *
 * In both cases `pageurl` is the full URL of the protected page.
 *
 * Every script URL has to be an https URL on an awswaf.com host. CapSkip POSTs
 * the solution to the host the script names, so anything else is refused before
 * the request is sent.
 *
 * Unlike reCAPTCHA or Turnstile, the answer is NOT a single token. It is two
 * values, captcha_voucher and existing_token, that only work as a pair.
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use CapSkip\CapSkip;

$solver = new CapSkip([
    'apiKey' => getenv('CAPSKIP_API_KEY') ?: 'capskip',
    'host' => getenv('CAPSKIP_HOST') ?: '127.0.0.1',
    'port' => (int) (getenv('CAPSKIP_PORT') ?: 8080),
]);

const SITEKEY = 'AQIDAHjcYuEXAMPLEghicBgQ/7bFaQZ+m5FKCMDnO+vTbNg96AHDh0IR5vgzHNceHYqZR+GOAAAAfjB8';
const IV = 'CgAFRDIjfgAAA5yY';
const CONTEXT = 'Rbp3r8fvbuhmrCqpUe8Mh1TLvs8OSKJZiSs2vCF4dt9rxVZI7xYJa2n5k/9r+XaXe7KUIu2I8nXTDHHkDvIOTDc2';
const CHALLENGE_JS = 'https://3f2a9c7d41b0.edge.sdk.awswaf.com/3f2a9c7d41b0/8e51d0c7a2f4/challenge.js';
const PAGE_URL = 'https://example.com/';

try {
    // --- Challenge page --------------------------------------------------
    $result = $solver->amazonWaf(SITEKEY, PAGE_URL, [
        'iv' => IV,
        'context' => CONTEXT,
        'challenge_script' => CHALLENGE_JS,
    ]);

    echo 'Captcha ID:      ', $result['captchaId'], PHP_EOL;
    echo 'Captcha voucher: ', substr($result['captcha_voucher'], 0, 48), '...', PHP_EOL;
    echo 'Existing token:  ', substr($result['existing_token'], 0, 48), '...', PHP_EOL;

    // iv and context go stale within minutes. If your copy of the page is
    // older than that, leave both out and CapSkip reads fresh ones from
    // PAGE_URL at solve time:
    //
    //   $result = $solver->amazonWaf(SITEKEY, PAGE_URL, [
    //       'challenge_script' => CHALLENGE_JS,
    //   ]);
    //
    // --- Widget page -----------------------------------------------------
    //
    //   $result = $solver->amazonWaf(
    //       'Xr9kEXAMPLE2bWQx1vTq8LmZ0aYc4dHs7nFp3gJt6uKw5eRi9AbCdEfGh=_0_1',
    //       'https://example.com/signup',
    //       ['jsapiScript' => 'https://5b7e0a19c3d8.us-east-1.captcha-sdk.awswaf.com/5b7e0a19c3d8/jsapi.js']
    //   );
    //
    // --- With a per-request proxy ----------------------------------------
    // Solve through the same proxy you will browse the site through, so the
    // token is minted from the address that goes on to use it.
    //
    //   $result = $solver->amazonWaf(SITEKEY, PAGE_URL, [
    //       'proxy' => ['type' => 'HTTP', 'uri' => 'login:password@1.2.3.4:8080'],
    //   ]);

    // Trade the pair for the aws-waf-token cookie: POST it as JSON to the
    // `voucher` endpoint that sits beside challenge.js, and the response's
    // `token` is the cookie value. Do this straight away -- the pair is
    // short-lived, so do not store it for later.
    $voucherUrl = substr(CHALLENGE_JS, 0, (int) strrpos(CHALLENGE_JS, '/')) . '/voucher';
    $body = json_encode([
        'captcha_voucher' => $result['captcha_voucher'],
        'existing_token' => $result['existing_token'],
    ]);
    echo 'Voucher URL:     ', $voucherUrl, PHP_EOL;

    //   $ch = curl_init($voucherUrl);
    //   curl_setopt_array($ch, [
    //       CURLOPT_POST => true,
    //       CURLOPT_POSTFIELDS => $body,
    //       CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
    //       CURLOPT_RETURNTRANSFER => true,
    //   ]);
    //   $response = json_decode((string) curl_exec($ch), true);
    //   $cookie = $response['token'];   // send as the aws-waf-token cookie
    echo 'Then send the response token as the aws-waf-token cookie.', PHP_EOL;
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), PHP_EOL;
    exit(1);
}

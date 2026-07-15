# CapSkip PHP SDK — Complete Tutorial

This tutorial takes you from zero to solving every captcha type CapSkip supports.
Work through it top to bottom, or jump to the section you need.

**Contents**

1. [How it works](#1-how-it-works)
2. [Install and configure](#2-install-and-configure)
3. [Your first solve](#3-your-first-solve)
4. [Image captcha](#4-image-captcha)
5. [reCAPTCHA v2](#5-recaptcha-v2)
6. [reCAPTCHA v3](#6-recaptcha-v3)
7. [Cloudflare Turnstile](#7-cloudflare-turnstile)
8. [Using a proxy](#8-using-a-proxy)
9. [Solving several captchas](#9-solving-several-captchas)
10. [The manual workflow](#10-the-manual-workflow)
11. [Return values](#11-return-values)
12. [Error handling](#12-error-handling)
13. [End-to-end: solve and submit](#13-end-to-end-solve-and-submit)
14. [Parameter reference](#14-parameter-reference)
15. [Best practices](#15-best-practices)

---

## 1. How it works

CapSkip is a **local** application that solves captchas on your own machine and
exposes a standard captcha-solver HTTP API (documented in the [CapSkip API docs](https://capskip.com/api-docs/)):

```
POST http://<host>:<port>/in.php   → submit a captcha, returns  OK|<id>
GET  http://<host>:<port>/res.php  → poll for the answer, returns  OK|<solution>
```

This SDK is a thin, friendly wrapper around that API. Every solve follows the
same three steps, which the SDK does for you:

1. **Submit** the captcha (`in.php`) and receive a captcha ID.
2. **Poll** the result endpoint (`res.php`) every few seconds while the answer is
   not ready.
3. **Return** the solution once CapSkip finishes.

You never have to write the polling loop yourself — call one method and get the
answer back.

---

## 2. Install and configure

### Install CapSkip

Download and launch the CapSkip desktop app from [capskip.com](https://capskip.com),
and leave it running. In **Settings**, note the **API port** (default `8080`) and,
if API-key validation is enabled, your **API key**.

### Install the SDK

```bash
composer require capskip/capskip
```

Verify:

```bash
php -r "require 'vendor/autoload.php'; echo CapSkip\CapSkip::VERSION, PHP_EOL;"
php examples/verify_connection.php     # checks CapSkip is reachable
```

### Configure the client

```php
use CapSkip\CapSkip;

$solver = new CapSkip([
    'apiKey' => 'capskip',        // your CapSkip API key (any string if validation is off)
    'host' => '127.0.0.1',        // where CapSkip is listening
    'port' => 8080,               // API port from CapSkip settings
    'defaultTimeout' => 120,      // seconds to wait for an image captcha
    'recaptchaTimeout' => 300,    // seconds to wait for reCAPTCHA / Turnstile
    'pollingInterval' => 5,       // max seconds between result polls (starts at 0.25s, backs off to this)
]);
```

In production, read configuration from the environment instead of hard-coding it:

```php
use CapSkip\CapSkip;

$solver = new CapSkip([
    'apiKey' => getenv('CAPSKIP_API_KEY') ?: 'capskip',
    'host' => getenv('CAPSKIP_HOST') ?: '127.0.0.1',
    'port' => (int) (getenv('CAPSKIP_PORT') ?: 8080),
]);
```

---

## 3. Your first solve

```php
use CapSkip\CapSkip;

$solver = new CapSkip(['host' => '127.0.0.1', 'port' => 8080]);

$result = $solver->recaptcha(
    '6Le-wvkS...your-sitekey',
    'https://example.com/page-with-recaptcha'
);

echo $result['code'];       // the g-recaptcha-response token
echo $result['captchaId'];  // CapSkip's internal ID for this solve
```

`$result` is always an associative array. The solution is in `$result['code']`.

---

## 4. Image captcha

Use `$solver->normal(...)` for classic distorted-text images. The SDK accepts four
input forms and auto-detects which one you passed:

```php
// 1. Local file path
$result = $solver->normal('captcha.png');

// 2. Remote image URL (the SDK downloads and encodes it)
$result = $solver->normal('https://example.com/captcha.jpg');

// 3. Base64 string (no file extension, longer than 50 characters)
$b64 = base64_encode(file_get_contents('captcha.png'));
$result = $solver->normal($b64);

// 4. Data-URI
$result = $solver->normal('data:image/png;base64,iVBORw0KGgo...');

echo $result['code'];   // the recognized text
```

Image captcha accepts only one extra option, `json`, which controls the raw
response format from CapSkip:

```php
$result = $solver->normal('captcha.png', ['json' => 1]);
```

> **Note:** Proxies are **not** supported for image captcha — passing one raises
> `ValidationException`. Proxies apply only to reCAPTCHA and Turnstile.

---

## 5. reCAPTCHA v2

`$solver->recaptcha($sitekey, $url)` handles reCAPTCHA v2 by default. The `$sitekey`
is the `data-sitekey` attribute of the widget; `$url` is the full page URL where it
appears.

```php
// Standard checkbox
$result = $solver->recaptcha('6Le-wvkS...', 'https://example.com/login');

// Invisible reCAPTCHA v2
$result = $solver->recaptcha('6Le-wvkS...', 'https://example.com', ['invisible' => 1]);

// Enterprise reCAPTCHA v2
$result = $solver->recaptcha('6Le-wvkS...', 'https://example.com', ['enterprise' => 1]);

// Enterprise with a data-s value (SDK alias: 'datas')
$result = $solver->recaptcha('6Le-wvkS...', 'https://example.com', [
    'enterprise' => 1,
    'datas' => 'Crb7Vs...',
]);

echo $result['code'];   // g-recaptcha-response token
```

Do **not** pass `version`, `action`, or `min_score` to a v2 solve — those belong
to v3 and will raise `ValidationException`.

---

## 6. reCAPTCHA v3

reCAPTCHA v3 is score-based. Pass `version => 'v3'` plus the `action` your target
page uses and, optionally, a minimum score.

```php
$result = $solver->recaptcha('6Le-wvkS...', 'https://example.com', [
    'version' => 'v3',
    'action' => 'submit',   // must match the action in grecaptcha.execute()
    'score' => 0.7,         // SDK alias for min_score (0.1 – 0.9)
    'enterprise' => 0,      // set 1 for Enterprise v3
]);

echo $result['code'];
```

`invisible` is a v2-only flag and is rejected for v3.

---

## 7. Cloudflare Turnstile

`$solver->turnstile($sitekey, $url)` solves Cloudflare Turnstile. The SDK
automatically requests the JSON response so it can return the **User-Agent**
Cloudflare expects.

```php
// Standalone widget
$result = $solver->turnstile('0x4AAAAAAA...', 'https://example.com');
echo $result['code'];            // cf-turnstile-response token
echo $result['userAgent'] ?? ''; // present when CapSkip returns it

// With an explicit action
$result = $solver->turnstile('0x4AAAAAAA...', 'https://example.com', ['action' => 'login']);

// Cloudflare challenge page (needs cData and chlPageData from the page)
$result = $solver->turnstile('0x4AAAAAAA...', 'https://example.com', [
    'action' => 'managed',
    'data' => 'your_cData_value',
    'pagedata' => 'your_chlPageData_value',
]);
```

> **Important:** For challenge pages you **must** send the returned token *and* use
> `$result['userAgent']` as the `User-Agent` header when you submit it. Mismatched
> User-Agents are the most common reason a valid token gets rejected.

---

## 8. Using a proxy

Solving through the same IP you will submit from greatly improves acceptance rates
for reCAPTCHA and Turnstile. Pass the proxy as an array with `type` and `uri`:

```php
$proxy = ['type' => 'HTTPS', 'uri' => 'user:pass@1.2.3.4:3128'];

$result = $solver->recaptcha('...', 'https://example.com', ['proxy' => $proxy]);
$result = $solver->turnstile('...', 'https://example.com', ['proxy' => $proxy]);
```

Supported proxy types: `HTTP`, `HTTPS`, `SOCKS5`, `SOCKS5H`. The `uri` may include
credentials (`login:password@host:port`) or be a bare `host:port`.

---

## 9. Solving several captchas

PHP executes synchronously, so each solve blocks until it finishes. To solve
several captchas, call the methods one after another:

```php
use CapSkip\CapSkip;

$solver = new CapSkip(['host' => '127.0.0.1', 'port' => 8080]);

$r1 = $solver->recaptcha('...', 'https://a.com');
$r2 = $solver->recaptcha('...', 'https://b.com', ['version' => 'v3', 'action' => 'submit']);
$r3 = $solver->turnstile('0x4A...', 'https://c.com');

echo $r1['code'], $r2['code'], $r3['code'];
```

`AsyncCapSkip` is exported as an alias of `CapSkip` for parity with the other
CapSkip SDKs; in PHP there is no separate asynchronous client. To run solves truly
in parallel, dispatch them across separate worker processes (for example with a
job queue).

---

## 10. The manual workflow

If you want to submit now and collect the answer later, use the two low-level steps
directly.

```php
use CapSkip\Exceptions\NetworkException;

// 1. Submit — returns the captcha ID immediately, without waiting.
$captchaId = $solver->send([
    'method' => 'userrecaptcha',
    'googlekey' => '6Le-wvkS...',
    'pageurl' => 'https://example.com',
]);

// 2. Poll once. NetworkException means "not ready yet" — retry.
while (true) {
    try {
        $code = $solver->getResult($captchaId);
        break;
    } catch (NetworkException $e) {
        sleep(5);
    }
}

echo $code;
```

Pass `1` as the second argument to `getResult` to get the full array (including
`userAgent` for Turnstile) instead of a plain string.

---

## 11. Return values

Every high-level solve method (`normal`, `recaptcha`, `turnstile`, `solve`) returns
an associative array:

```php
[
    'captchaId' => '12345',       // CapSkip's internal ID for this solve
    'code' => 'TOKEN_OR_TEXT',    // the solution: text for images, token otherwise
    'userAgent' => 'Mozilla/...', // Turnstile only, when CapSkip provides it
]
```

`$solver->send()` returns just the ID string. `$solver->getResult()` returns the
solution string (or an array when `json=1`).

---

## 12. Error handling

The SDK raises four exception types, all subclasses of `CapSkip\Exceptions\CapSkipError`:

| Exception | When it is raised |
|---|---|
| `ValidationException` | Invalid or unsupported parameters (e.g. proxy on image captcha) |
| `NetworkException` | CapSkip is unreachable, or the captcha is not ready yet |
| `ApiException` | CapSkip returned an error code (e.g. `ERROR_WRONG_USER_KEY`) |
| `TimeoutException` | Polling exceeded the configured timeout |

```php
use CapSkip\CapSkip;
use CapSkip\Exceptions\ValidationException;
use CapSkip\Exceptions\NetworkException;
use CapSkip\Exceptions\ApiException;
use CapSkip\Exceptions\TimeoutException;

$solver = new CapSkip(['host' => '127.0.0.1', 'port' => 8080]);

try {
    $result = $solver->recaptcha('...', 'https://example.com');
    echo $result['code'];
} catch (ValidationException $e) {
    echo 'Bad parameters: ' . $e->getMessage();
} catch (NetworkException $e) {
    echo 'Is CapSkip running? ' . $e->getMessage();
} catch (ApiException $e) {
    echo 'CapSkip returned an error: ' . $e->getMessage();
} catch (TimeoutException $e) {
    echo 'Gave up waiting: ' . $e->getMessage();
}
```

You can also catch them all at once with the base class:

```php
use CapSkip\Exceptions\CapSkipError;

try {
    $result = $solver->turnstile('...', '...');
} catch (CapSkipError $e) {
    echo 'Solve failed: ' . $e->getMessage();
}
```

---

## 13. End-to-end: solve and submit

A realistic flow — solve a reCAPTCHA, then submit the token to the target site
through the **same** proxy:

```php
use CapSkip\CapSkip;
use CapSkip\Exceptions\CapSkipError;

$solver = new CapSkip([
    'apiKey' => getenv('CAPSKIP_API_KEY') ?: 'capskip',
    'host' => '127.0.0.1',
    'port' => 8080,
]);

$sitekey = '6Le-wvkS...your-sitekey';
$loginUrl = 'https://example.com/login';
$proxy = ['type' => 'HTTP', 'uri' => '1.2.3.4:3128'];

try {
    $solved = $solver->recaptcha($sitekey, $loginUrl, ['proxy' => $proxy]);
} catch (CapSkipError $e) {
    exit('Could not solve captcha: ' . $e->getMessage());
}

$token = $solved['code'];

// Submit the form using the same proxy so the IP matches.
$ch = curl_init($loginUrl);
curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_PROXY => $proxy['uri'],
    CURLOPT_POSTFIELDS => http_build_query([
        'username' => 'myuser',
        'password' => 'mypass',
        'g-recaptcha-response' => $token,
    ]),
]);
curl_exec($ch);
echo curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
```

For Turnstile challenge pages, also set the User-Agent header:

```php
$solved = $solver->turnstile('0x4A...', $challengeUrl, ['data' => 'cData', 'pagedata' => 'chlPageData']);

$ch = curl_init($challengeUrl);
curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_USERAGENT => $solved['userAgent'],
    CURLOPT_POSTFIELDS => http_build_query(['cf-turnstile-response' => $solved['code']]),
]);
curl_exec($ch);
curl_close($ch);
```

---

## 14. Parameter reference

### Solve methods

| Method | Signature |
|---|---|
| Image | `normal(string $file, array $options = [])` |
| reCAPTCHA | `recaptcha(string $sitekey, string $url, array $options = [])` |
| Turnstile | `turnstile(string $sitekey, string $url, array $options = [])` |
| Manual submit | `send(array $params): string` |
| Manual poll | `getResult(string $id, int $json = 0)` |

`recaptcha` options include `version` (`v2`/`v3`), `enterprise`, `invisible`,
`action`, `score`, and `proxy`. `turnstile` options include `action`, `data`,
`pagedata`, and `proxy`.

### Convenience aliases

The SDK accepts friendly names and converts them to the raw API parameters:

| SDK name | CapSkip API parameter |
|---|---|
| `url` | `pageurl` |
| `score`, `minScore` | `min_score` |
| `datas`, `data_s` | `data-s` |
| `proxy` (array) | `proxy` + `proxytype` strings |

Anything CapSkip does not document for a given captcha type is rejected with
`ValidationException`, so typos fail fast instead of silently doing nothing.

---

## 15. Best practices

- **Keep CapSkip running.** The SDK talks to a local app; if it is not running you
  get `NetworkException`.
- **Use the token immediately.** reCAPTCHA and Turnstile tokens expire within a
  couple of minutes.
- **Match sitekey and pageurl exactly** to the page the widget loads on.
- **Solve and submit from the same IP** (same proxy) for reCAPTCHA and Turnstile.
- **Never commit secrets.** Read `CAPSKIP_API_KEY` and proxy credentials from the
  environment, not source code.
- **Tune timeouts** for slow captcha types with `recaptchaTimeout` and
  `defaultTimeout`.

---

### Where to go next

- [API Reference](API_REFERENCE.md) — every method, parameter, and endpoint
- [Getting Started](GETTING_STARTED.md) — installation walkthrough
- [Troubleshooting](TROUBLESHOOTING.md) — fixes for common errors
- [CapSkip API docs](https://capskip.com/api-docs/) — the raw HTTP API

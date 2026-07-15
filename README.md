# CapSkip PHP SDK

[![PHP 8.0+](https://img.shields.io/badge/php-8.0%2B-777bb4.svg)](https://www.php.net/)
[![License: MIT](https://img.shields.io/badge/License-MIT-green.svg)](LICENSE)
[![Tests](https://github.com/capskip/capskip-php/actions/workflows/ci.yml/badge.svg)](https://github.com/capskip/capskip-php/actions/workflows/ci.yml)

Official PHP client for the [CapSkip](https://capskip.com) **local** captcha solver.

CapSkip runs on your machine and exposes a standard captcha-solver HTTP API (the familiar `in.php` / `res.php` endpoints). This SDK wraps that API with clean, familiar method names, so you can solve captchas locally — no cloud service and no per-solve API fees beyond your CapSkip license.

---

## Quick start (5 minutes)

### 1. Install CapSkip

Download and run the CapSkip desktop app from [capskip.com](https://capskip.com). Leave it running in the background.

In CapSkip settings, note:

- **API port** (default: `8080`)
- **API key** (optional — if validation is disabled, any string works)

### 2. Install the SDK

```bash
composer require capskip/capskip
```

Or from source:

```bash
git clone https://github.com/capskip/capskip-php.git
cd capskip-php
composer install
```

### 3. Solve your first captcha

```php
<?php

require 'vendor/autoload.php';

use CapSkip\CapSkip;

$solver = new CapSkip(['host' => '127.0.0.1', 'port' => 8080]);

$result = $solver->recaptcha(
    'YOUR_SITEKEY',
    'https://example.com/page-with-recaptcha'
);

echo $result['code']; // g-recaptcha-response token
```

> **Prerequisite:** CapSkip must be running before you call the SDK. If you see a connection error, see [Troubleshooting](docs/TROUBLESHOOTING.md).

---

## Supported captcha types

| Type | SDK method |
|---|---|
| Image CAPTCHA (distorted text) | `$solver->normal($file)` |
| reCAPTCHA v2 (checkbox) | `$solver->recaptcha($sitekey, $url)` |
| reCAPTCHA v2 Invisible | `$solver->recaptcha($sitekey, $url, ['invisible' => 1])` |
| reCAPTCHA v2 Enterprise | `$solver->recaptcha($sitekey, $url, ['enterprise' => 1])` |
| reCAPTCHA v3 | `$solver->recaptcha($sitekey, $url, ['version' => 'v3'])` |
| reCAPTCHA v3 Enterprise | `$solver->recaptcha($sitekey, $url, ['version' => 'v3', 'enterprise' => 1])` |
| Cloudflare Turnstile (widget) | `$solver->turnstile($sitekey, $url)` |
| Cloudflare Turnstile (challenge page) | `$solver->turnstile($sitekey, $url, ['data' => ..., 'pagedata' => ...])` |

---

## Documentation

| Guide | Description |
|---|---|
| [Tutorial](docs/TUTORIAL.md) | Complete walkthrough of every captcha type |
| [Getting Started](docs/GETTING_STARTED.md) | Full setup: CapSkip app, SDK install, first script |
| [API Reference](docs/API_REFERENCE.md) | All classes, methods, parameters, and return values |
| [Examples](examples/) | Ready-to-run scripts for every captcha type |
| [Troubleshooting](docs/TROUBLESHOOTING.md) | Connection errors, timeouts, proxy issues |
| [Contributing](CONTRIBUTING.md) | Development setup, tests, pull requests |
| [Changelog](CHANGELOG.md) | Release history |

---

## Configuration

```php
use CapSkip\CapSkip;

$solver = new CapSkip([
    'apiKey' => 'capskip',        // your CapSkip API key (or any string if validation is off)
    'host' => '127.0.0.1',        // CapSkip host
    'port' => 8080,               // CapSkip port from app settings
    'defaultTimeout' => 120,      // seconds — image captcha polling timeout
    'recaptchaTimeout' => 300,    // seconds — reCAPTCHA / Turnstile polling timeout
    'pollingInterval' => 5,       // max seconds between res.php polls (starts at 0.25s, backs off to this)
]);
```

Use environment variables in production:

```bash
# Linux / macOS
export CAPSKIP_API_KEY="your-key"
export CAPSKIP_HOST="127.0.0.1"
export CAPSKIP_PORT="8080"
```

```powershell
# Windows PowerShell
$env:CAPSKIP_API_KEY = "your-key"
$env:CAPSKIP_HOST = "127.0.0.1"
$env:CAPSKIP_PORT = "8080"
```

```php
use CapSkip\CapSkip;

$solver = new CapSkip([
    'apiKey' => getenv('CAPSKIP_API_KEY') ?: 'capskip',
    'host' => getenv('CAPSKIP_HOST') ?: '127.0.0.1',
    'port' => (int) (getenv('CAPSKIP_PORT') ?: 8080),
]);
```

---

## Usage examples

### Image captcha

```php
$result = $solver->normal('captcha.png');
$result = $solver->normal('https://example.com/captcha.jpg');
$result = $solver->normal('data:image/png;base64,iVBORw0KGgo...');
echo $result['code'];
```

### reCAPTCHA v2 / v3

```php
// reCAPTCHA v2
$v2 = $solver->recaptcha('...', 'https://example.com');

// reCAPTCHA v3
$v3 = $solver->recaptcha('...', 'https://example.com', [
    'version' => 'v3',
    'action' => 'submit',
    'score' => 0.7,
]);
```

### Cloudflare Turnstile

```php
$result = $solver->turnstile('0x4AAAAAAA...', 'https://example.com');
```

### With a proxy (reCAPTCHA & Turnstile only)

```php
// Proxy is not supported for image captcha
$result = $solver->recaptcha('...', 'https://example.com', [
    'proxy' => ['type' => 'HTTPS', 'uri' => 'user:pass@1.2.3.4:3128'],
]);
$result = $solver->turnstile('...', 'https://example.com', [
    'proxy' => ['type' => 'HTTP', 'uri' => '1.2.3.4:3128'],
]);
```

### Solving several captchas

```php
use CapSkip\CapSkip;

$solver = new CapSkip();
$r1 = $solver->recaptcha('...', 'https://a.com');
$r2 = $solver->turnstile('...', 'https://b.com');
echo $r1['code'], $r2['code'];
```

> PHP executes synchronously, so each solve blocks until it finishes. `AsyncCapSkip` is exported as an alias of `CapSkip` for parity with the other CapSkip SDKs — code ported from them keeps working unchanged.

More examples: [`examples/`](examples/)

---

## Return value

Every solve method returns an associative array:

```php
[
    'captchaId' => '12345',   // internal ID from CapSkip
    'code' => 'TOKEN_OR_TEXT', // solution — text for image, token for reCAPTCHA/Turnstile
    'userAgent' => '...',      // Turnstile only — use when submitting challenge-page tokens
]
```

---

## Error handling

```php
use CapSkip\CapSkip;
use CapSkip\Exceptions\ValidationException;
use CapSkip\Exceptions\NetworkException;
use CapSkip\Exceptions\ApiException;
use CapSkip\Exceptions\TimeoutException;

try {
    $result = $solver->recaptcha('...', '...');
} catch (ValidationException $e) {
    // invalid parameters
} catch (NetworkException $e) {
    // CapSkip not running, or captcha not ready (manual polling)
} catch (ApiException $e) {
    // API returned an error code
} catch (TimeoutException $e) {
    // polling timeout exceeded
}
```

All four extend `CapSkip\Exceptions\CapSkipError`, so you can catch them all at once with the base class.

---

## Requirements

- PHP 8.0 or newer
- The `curl` and `json` extensions (bundled with most PHP installs)
- No other runtime dependencies

---

## Development

```bash
git clone https://github.com/capskip/capskip-php.git
cd capskip-php
composer install
composer test
```

See [CONTRIBUTING.md](CONTRIBUTING.md) for the full development workflow.

---

## Links

- [CapSkip website](https://capskip.com)
- [CapSkip API docs](https://capskip.com/api-docs/)
- [Report an issue](https://github.com/capskip/capskip-php/issues)
- [Packagist package](https://packagist.org/packages/capskip/capskip)

---

## License

MIT — see [LICENSE](LICENSE).

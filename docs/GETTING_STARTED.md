# Getting Started

This guide walks you through installing CapSkip, installing the PHP SDK, and running your first captcha solve.

---

## Prerequisites

| Requirement | Details |
|---|---|
| **CapSkip app** | Windows desktop app from [capskip.com](https://capskip.com) |
| **PHP** | 8.0 or newer with the `curl` and `json` extensions |
| **Composer** | [getcomposer.org](https://getcomposer.org/) |
| **Network** | SDK talks to CapSkip on `localhost` — no internet required for the API itself |

---

## Step 1 — Install and configure CapSkip

1. Download CapSkip from [capskip.com](https://capskip.com).
2. Install and launch the application.
3. Open **Settings** and confirm:
   - **Port** — default is `8080` (remember this value)
   - **API key validation** — if enabled, copy your API key; if disabled, any string (e.g. `capskip`) is accepted

CapSkip exposes a standard captcha-solver API:

```
POST http://127.0.0.1:<port>/in.php   → submit captcha, returns OK|<id>
GET  http://127.0.0.1:<port>/res.php  → poll result, returns OK|<answer>
```

### Verify CapSkip is running

**Windows (PowerShell):**

```powershell
Invoke-WebRequest "http://127.0.0.1:8080/res.php?key=capskip&action=get&id=0" -UseBasicParsing
```

You should get a response (even an error like `ERROR_WRONG_CAPTCHA_ID` confirms the server is up).

**Linux / macOS:**

```bash
curl "http://127.0.0.1:8080/res.php?key=capskip&action=get&id=0"
```

---

## Step 2 — Install the PHP SDK

### From Packagist (recommended)

```bash
composer require capskip/capskip
```

### From source (development)

```bash
git clone https://github.com/capskip/capskip-php.git
cd capskip-php
composer install
```

### Verify installation

```bash
php -r "require 'vendor/autoload.php'; echo CapSkip\CapSkip::VERSION, PHP_EOL;"
```

Expected output: `1.0.2` (or your installed version).

### Verify CapSkip connectivity

```bash
php examples/verify_connection.php
```

If CapSkip is running, you should see `Status: OK — CapSkip is reachable`.

---

## Step 3 — Your first script

Create `solve_recaptcha.php`:

```php
<?php

require 'vendor/autoload.php';

use CapSkip\CapSkip;
use CapSkip\Exceptions\NetworkException;
use CapSkip\Exceptions\TimeoutException;

$solver = new CapSkip([
    'apiKey' => getenv('CAPSKIP_API_KEY') ?: 'capskip',
    'host' => getenv('CAPSKIP_HOST') ?: '127.0.0.1',
    'port' => (int) (getenv('CAPSKIP_PORT') ?: 8080),
]);

$sitekey = '6Le-wvkSAAAAAPBMRTvw0Q4Muexq9bi0DJwx_mJ-'; // replace with your target page sitekey
$pageUrl = 'https://example.com/login';                 // replace with your target page URL

try {
    $result = $solver->recaptcha($sitekey, $pageUrl);
    echo 'Captcha ID: ' . $result['captchaId'] . PHP_EOL;
    echo 'Token:      ' . substr($result['code'], 0, 80) . ' ...' . PHP_EOL;
} catch (NetworkException $e) {
    echo 'Cannot reach CapSkip — is the app running? ' . $e->getMessage() . PHP_EOL;
} catch (TimeoutException $e) {
    echo 'Timed out: ' . $e->getMessage() . PHP_EOL;
}
```

Run it:

```bash
php solve_recaptcha.php
```

---

## Step 4 — Run the bundled examples

Clone the repository (if you haven't already) and run an example:

```bash
cd capskip-php
php examples/recaptcha.php
```

| Example | What it demonstrates |
|---|---|
| `image_captcha.php` | Image captcha from a file |
| `recaptcha.php` | reCAPTCHA v2 |
| `turnstile.php` | Cloudflare Turnstile widget |
| `geetest.php` | GeeTest v3 slider, including fetching a fresh `gt`/`challenge` pair |
| `async_example.php` | Solving several captcha types in a row |
| `verify_connection.php` | Check CapSkip is running |

---

## Environment variables

| Variable | Default | Description |
|---|---|---|
| `CAPSKIP_API_KEY` | `capskip` | API key sent with every request |
| `CAPSKIP_HOST` | `127.0.0.1` | CapSkip host |
| `CAPSKIP_PORT` | `8080` | CapSkip port |

Example `.env` file (load with [vlucas/phpdotenv](https://packagist.org/packages/vlucas/phpdotenv) if you use it):

```env
CAPSKIP_API_KEY=capskip
CAPSKIP_HOST=127.0.0.1
CAPSKIP_PORT=8080
```

---

## Next steps

- [Tutorial](TUTORIAL.md) — complete walkthrough of every captcha type
- [API Reference](API_REFERENCE.md) — all methods and parameters
- [Troubleshooting](TROUBLESHOOTING.md) — fix common errors
- [CapSkip API docs](https://capskip.com/api-docs/) — raw HTTP API reference

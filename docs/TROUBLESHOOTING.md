# Troubleshooting

Common issues when using the CapSkip PHP SDK and how to fix them.

---

## Connection refused / CapSkip not reachable

**Symptom**

```
NetworkException: ...
Failed to connect to 127.0.0.1 port 8080: Connection refused
```

**Cause:** CapSkip desktop app is not running, or the port is wrong.

**Fix**

1. Launch the CapSkip application.
2. Confirm the API server is enabled in Settings.
3. Match the port in your SDK config:

```php
$solver = new CapSkip(['host' => '127.0.0.1', 'port' => 8080]); // use your actual port
```

4. Test with curl:

```bash
curl "http://127.0.0.1:8080/res.php?key=capskip&action=get&id=0"
```

---

## TimeoutException — captcha not solved in time

**Symptom**

```
TimeoutException: timeout 300 exceeded
```

**Cause:** CapSkip needs more time, or the captcha failed silently.

**Fix**

1. Increase the timeout:

```php
$solver = new CapSkip(['recaptchaTimeout' => 600, 'defaultTimeout' => 180]);
```

2. Adjust the poll interval (CapSkip docs recommend ~5 s for reCAPTCHA):

```php
$solver = new CapSkip(['pollingInterval' => 5]);
```

3. Check CapSkip app logs for solve errors on that captcha type.

---

## ApiException — ERROR_WRONG_USER_KEY

**Symptom**

```
ApiException: ERROR_WRONG_USER_KEY
```

**Cause:** API key validation is enabled in CapSkip but the key is wrong.

**Fix**

1. Copy the exact API key from CapSkip Settings.
2. Pass it to the SDK:

```php
$solver = new CapSkip(['apiKey' => 'your-actual-key']);
```

Or disable API key validation in CapSkip Settings (development only).

---

## ApiException — ERROR_BAD_PARAMETERS

**Symptom**

```
ApiException: ERROR_BAD_PARAMETERS
```

**Cause:** Missing or invalid API parameters.

**Fix**

- **reCAPTCHA:** ensure `sitekey` and `url` are correct.
- **Turnstile challenge page:** include `data` and `pagedata` if required.
- **Image captcha:** ensure the file exists or the base64 string is valid.

---

## ValidationException — File not found

**Symptom**

```
ValidationException: File not found: captcha.png
```

**Fix**

- Use an absolute path or verify the working directory.
- For base64, pass a string with no file extension and length > 50, or use a data-URI:

```php
$solver->normal('data:image/png;base64,iVBORw0KGgo...');
```

---

## reCAPTCHA token rejected by target site

**Symptom:** SDK returns a token, but the website rejects it.

**Possible causes & fixes**

| Cause | Fix |
|---|---|
| Wrong `sitekey` or `pageurl` | Must match the exact page where the widget loads |
| IP mismatch | Use the same proxy for solving and submitting the form |
| Enterprise / invisible flag wrong | Set `enterprise => 1` or `invisible => 1` if the page uses them |
| v3 action mismatch | Pass the correct `action` from `grecaptcha.execute()` |
| Token expired | Use the token immediately after receiving it |

**Proxy example (same IP for solve and submit):**

```php
$proxy = ['type' => 'HTTP', 'uri' => '1.2.3.4:3128'];
$result = $solver->recaptcha('...', '...', ['proxy' => $proxy]);
// submit form using the same proxy
```

---

## Turnstile challenge page — token works but page still blocks

**Symptom:** Token received but Cloudflare still challenges.

**Fix:** For challenge pages, CapSkip returns a User-Agent that must be used when submitting the token. The SDK polls Turnstile with `json=1` automatically and exposes it as `$result['userAgent']` — send the token *and* set that value as your `User-Agent` header.

---

## NetworkException during manual polling

**Symptom:** `getResult()` keeps throwing `NetworkException`.

**This is expected** while the captcha is still processing. Catch it and retry:

```php
use CapSkip\Exceptions\NetworkException;

while (true) {
    try {
        $code = $solver->getResult($captchaId);
        break;
    } catch (NetworkException $e) {
        sleep(5);
    }
}
```

---

## Autoload / class not found after install

```bash
composer dump-autoload
php -r "require 'vendor/autoload.php'; echo CapSkip\CapSkip::VERSION, PHP_EOL;"
```

Make sure your entry script requires Composer's autoloader:

```php
require 'vendor/autoload.php';
```

---

## `curl` extension not enabled

**Symptom**

```
Call to undefined function curl_init()
```

**Fix:** Enable the `curl` extension in your `php.ini` (uncomment `extension=curl`)
and restart your PHP process. Verify with:

```bash
php -m | grep curl
```

---

## Still stuck?

1. [CapSkip API docs](https://capskip.com/api-docs/)
2. [GitHub Issues](https://github.com/capskip/capskip-php/issues)
3. CapSkip support: support@capskip.com

When opening an issue, include:

- PHP version (`php --version`)
- SDK version (`composer show capskip/capskip`)
- CapSkip port and captcha type
- Full error message / stack trace (redact sitekeys/tokens)

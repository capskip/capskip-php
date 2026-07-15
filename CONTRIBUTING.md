# Contributing to CapSkip PHP SDK

Thank you for helping improve the CapSkip PHP SDK. This document explains how to set up your environment, run tests, and submit changes.

---

## Prerequisites

- PHP 8.0 or newer with the `curl` and `json` extensions
- [Composer](https://getcomposer.org/)
- Git
- CapSkip desktop app (for integration testing against a live instance)

---

## Development setup

```bash
# Clone the repository
git clone https://github.com/capskip/capskip-php.git
cd capskip-php

# Install dev dependencies (PHPUnit)
composer install
```

There are no runtime dependencies — the SDK relies only on the `curl` and `json`
extensions bundled with PHP.

---

## Running tests

Tests mock the HTTP layer — CapSkip does not need to be running. The integration
suite boots a local mock server with PHP's built-in web server, so no CapSkip app
or network access is required.

```bash
# Run all tests
composer test

# Or directly
./vendor/bin/phpunit

# Readable (testdox) output
./vendor/bin/phpunit --testdox
```

### Test structure

| File | Description |
|---|---|
| `tests/MockApiClient.php` | Mock `ApiClient` recording sent params |
| `tests/AbstractTestCase.php` | Base test case + shared assertions |
| `tests/NormalTest.php` | Image captcha unit tests |
| `tests/RecaptchaTest.php` | reCAPTCHA unit tests |
| `tests/TurnstileTest.php` | Turnstile unit tests |
| `tests/PollParsingTest.php` | `res.php` response parsing |
| `tests/MockServer.php` + `tests/server/router.php` | Local mock CapSkip server |
| `tests/IntegrationTest.php` | End-to-end tests driving the real HTTP layer |

Unit tests verify that SDK methods send the correct parameters to the CapSkip API.
Integration tests spin up a local mock server and exercise the full submit/poll
round trip.

---

## Code style

- Match the existing code style in `src/`
- Target PHP 8.0+ and keep the SDK dependency-free (only `ext-curl` / `ext-json`)
- Keep changes focused — one feature or fix per pull request
- Add or update tests for any behavior change
- Update documentation in `docs/` and `README.md` when adding features

---

## Pull request process

1. Fork the repository and create a feature branch:

   ```bash
   git checkout -b feature/my-improvement
   ```

2. Make your changes and ensure tests pass:

   ```bash
   composer test
   ```

3. Update `CHANGELOG.md` under `[Unreleased]` if applicable.

4. Push and open a pull request against `main`.

5. Fill in the pull request template completely.

---

## Reporting bugs

Use the [Bug Report issue template](.github/ISSUE_TEMPLATE/bug_report.yml) and include:

- PHP version
- SDK version
- CapSkip port and captcha type
- Minimal reproduction steps
- Full error / stack trace (redact secrets)

---

## Feature requests

CapSkip only supports: **image captcha**, **reCAPTCHA v2/v3**, and **Cloudflare Turnstile**.

Before requesting a new captcha type, confirm it is supported by [CapSkip API docs](https://capskip.com/api-docs/). Use the [Feature Request template](.github/ISSUE_TEMPLATE/feature_request.yml) for SDK improvements.

---

## Project structure

```
src/                  # Package source
docs/                 # Documentation
examples/             # Runnable example scripts
tests/                # Unit and integration tests
.github/              # GitHub Actions and templates
```

---

## License

By contributing, you agree that your contributions will be licensed under the [MIT License](LICENSE).

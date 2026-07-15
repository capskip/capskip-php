# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.0.2] - 2026-07-15

### Changed

- Version aligned with the CapSkip Python and Node.js SDKs, which ship the
  json-submit fix as 1.0.2. No functional changes to the PHP SDK since 1.0.1,
  which already carried both the empty-body and json-submit fixes.

## [1.0.1] - 2026-07-15

### Fixed

- Polling no longer crashes with `ApiException: cannot recognize response` when
  CapSkip returns an empty response body. CapSkip returns an empty body while no
  result is available yet — briefly right after a captcha is submitted (before it
  reports `CAPCHA_NOT_READY`), for an unknown id, and after a solved token has
  already been read. The SDK now treats an empty body as "not ready" and keeps
  polling, so `recaptcha()`, `turnstile()`, and `normal()` solve reliably instead
  of failing on the first poll.
- Submitting with `json=1` no longer fails with `ApiException: cannot recognize
  response`. With `json=1` CapSkip's `in.php` returns `{"status":1,"request":"<id>"}`
  instead of `OK|<id>`; `send()` now parses both forms, so `normal(..., ['json' => 1])`
  and the equivalent reCAPTCHA/Turnstile calls submit and solve correctly.

## [1.0.0] - 2026-07-15

### Added

- Initial release of the CapSkip PHP SDK
- `CapSkip` client for the local CapSkip API
- `AsyncCapSkip` / `AsyncApiClient` exported as aliases for cross-SDK parity
  (PHP executes synchronously)
- Image CAPTCHA solving via `normal()` (file, URL, base64, or data-URI)
- reCAPTCHA v2 / v3 solving via `recaptcha()` (invisible, enterprise, proxy)
- Cloudflare Turnstile solving via `turnstile()` (widget and challenge page)
- Turnstile automatically polls with `json=1` and returns `userAgent` when provided
- Manual workflow: `send()`, `getResult()`, `solve()`
- Adaptive result polling — starts at 0.25s and backs off (doubling) up to the
  configured `pollingInterval`, so fast solves (e.g. image captchas) return in a
  fraction of a second; `pollingInterval` also accepts sub-second (float) values
- Familiar parameter aliases (`url`→`pageurl`, `score`→`min_score`, etc.)
- Proxy support via array format `['type' => '...', 'uri' => '...']`
- Strict per-captcha parameter validation — only documented parameters are accepted
- Exception hierarchy: `ValidationException`, `NetworkException`, `ApiException`, `TimeoutException`
- Zero runtime dependencies — built on the `curl` and `json` extensions
- Example scripts for every supported captcha type
- Unit and integration tests (PHPUnit) with a mocked API client and a local mock server
- Documentation: Tutorial, Getting Started, API Reference, Troubleshooting

[1.0.2]: https://github.com/capskip/capskip-php/releases/tag/v1.0.2
[1.0.1]: https://github.com/capskip/capskip-php/releases/tag/v1.0.1
[1.0.0]: https://github.com/capskip/capskip-php/releases/tag/v1.0.0

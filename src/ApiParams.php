<?php

declare(strict_types=1);

namespace CapSkip;

use CapSkip\Exceptions\ValidationException;

/** CapSkip API parameter validation (https://capskip.com/api-docs/). */
final class ApiParams
{
    /** @var array<int, string> */
    public const NORMAL_SUBMIT = ['method', 'body', 'json', 'file'];

    /** @var array<int, string> */
    public const RECAPTCHA_V2_SUBMIT = [
        'method', 'googlekey', 'pageurl', 'enterprise', 'invisible', 'data-s', 'json',
        'proxy', 'proxytype',
    ];

    /** @var array<int, string> */
    public const RECAPTCHA_V3_SUBMIT = [
        'method', 'version', 'googlekey', 'pageurl', 'enterprise', 'action', 'min_score',
        'json', 'proxy', 'proxytype',
    ];

    /** @var array<int, string> */
    public const TURNSTILE_SUBMIT = [
        'method', 'sitekey', 'pageurl', 'action', 'data', 'pagedata', 'json',
        'proxy', 'proxytype',
    ];

    /** @var array<int, string> */
    public const GEETEST_SUBMIT = [
        'method', 'gt', 'challenge', 'pageurl', 'api_server', 'json',
        'proxy', 'proxytype',
    ];

    /** @var array<int, string> */
    public const ALTCHA_SUBMIT = [
        'method', 'pageurl', 'challenge_url', 'challenge_json', 'json',
        'proxy', 'proxytype',
    ];

    /** @var array<int, string> */
    public const CAPY_SUBMIT = [
        'method', 'captchakey', 'pageurl', 'api_server', 'version', 'useragent', 'json',
        'proxy', 'proxytype',
    ];

    /** @var array<int, string> */
    public const CAPTCHAFOX_SUBMIT = [
        'method', 'sitekey', 'pageurl', 'api_server', 'useragent', 'json',
        'proxy', 'proxytype',
    ];

    /** @var array<int, string> */
    public const FRIENDLY_CAPTCHA_SUBMIT = [
        'method', 'sitekey', 'pageurl', 'version', 'module_script', 'nomodule_script',
        'api_server', 'useragent', 'json', 'proxy', 'proxytype',
    ];

    /** @var array<int, string> */
    public const AMAZON_WAF_SUBMIT = [
        'method', 'sitekey', 'pageurl', 'iv', 'context', 'challenge_script',
        'captcha_script', 'jsapiScript', 'json', 'proxy', 'proxytype',
    ];

    /**
     * The script parameters of an AWS WAF task. CapSkip POSTs the solution to
     * the host these name, so it refuses any that is not a genuine AWS WAF one.
     *
     * @var array<int, string>
     */
    public const AMAZON_WAF_SCRIPTS = ['challenge_script', 'captcha_script', 'jsapiScript'];

    /**
     * The server's own test: one or more [A-Za-z0-9_-] path segments, then one
     * of the three AWS WAF script files.
     */
    private const AMAZON_WAF_SCRIPT_PATH = '~^(/[A-Za-z0-9_-]+)+/(challenge|captcha|jsapi)\.js$~';

    /** @var array<int, string> */
    public const HCAPTCHA_SUBMIT = [
        'method', 'sitekey', 'pageurl', 'invisible', 'data', 'useragent', 'json',
        'proxy', 'proxytype',
    ];

    /** @var array<int, string> */
    public const DATADOME_SUBMIT = [
        'method', 'captcha_url', 'pageurl', 'useragent', 'json', 'proxy', 'proxytype',
    ];

    /**
     * Every DataDome challenge is served from this domain or a subdomain of it
     * (geo.captcha-delivery.com in practice). CapSkip refuses any other host.
     */
    public const DATADOME_CHALLENGE_DOMAIN = 'captcha-delivery.com';

    /**
     * The two challenge pages: /captcha/ draws the slider, /interstitial/ runs
     * the device check. Any other path renders nothing to solve.
     *
     * @var array<int, string>
     */
    public const DATADOME_CHALLENGE_PATHS = ['/captcha', '/interstitial'];

    /** A Chrome version as Chrome writes it in its User-Agent: four dotted numbers. */
    private const CHROME_VERSION = '~\bChrome/(\d+)\.\d+\.\d+\.\d+\b~';

    /**
     * Chrome-derived browsers and non-desktop builds. A User-Agent carrying any
     * of these is not stock Windows Chrome, so a cookie earned under it would be
     * refused on replay; the server answers ERROR_WRONG_USERAGENT for each.
     *
     * @var array<int, string>
     */
    private const NOT_STOCK_WINDOWS_CHROME = [
        'Edg/', 'EdgA/', 'OPR/', 'YaBrowser/', 'SamsungBrowser/', 'Vivaldi/',
        'HeadlessChrome/', 'CriOS/', 'Mobile', 'Android',
    ];

    /**
     * CapSkip solves the puzzle family only. `avatar` is a different challenge
     * behind a different endpoint; the server refuses it at submit time rather
     * than answering it with a puzzle answer, which would bill for a solve the
     * target site rejects.
     *
     * @var array<int, string>
     */
    public const CAPY_VERSIONS = ['puzzle'];

    /**
     * Both spellings the server accepts, and the bare digits it also takes.
     *
     * @var array<int, string>
     */
    public const FRIENDLY_CAPTCHA_VERSIONS = ['v1', 'v2', '1', '2'];

    /**
     * The only values CapSkip maps to a proxy scheme; it answers
     * ERROR_BAD_PARAMETERS for anything else, SOCKS4 included. Matched
     * case-insensitively, as the server does.
     *
     * @var array<int, string>
     */
    public const PROXY_TYPES = ['HTTP', 'HTTPS', 'SOCKS5', 'SOCKS5H'];

    /** @var array<string, string> */
    private const PARAM_ALIASES = [
        'url' => 'pageurl',
        'score' => 'min_score',
        'minScore' => 'min_score',
        'datas' => 'data-s',
        'data_s' => 'data-s',
        'apiServer' => 'api_server',
        'api_subdomain' => 'api_server',
        'challengeUrl' => 'challenge_url',
        'challengeURL' => 'challenge_url',
        'challengeJson' => 'challenge_json',
        'challengeJSON' => 'challenge_json',
        // The server reads userAgent and useragent interchangeably on every
        // method that takes one, so the SDK settles on the lowercase spelling
        // its parameter tables document and accepts the camelCase one callers
        // arrive with.
        'userAgent' => 'useragent',
        'user_agent' => 'useragent',
        'captchaKey' => 'captchakey',
        'moduleScript' => 'module_script',
        'nomoduleScript' => 'nomodule_script',
        'noModuleScript' => 'nomodule_script',
        // AWS WAF: the API documents challenge_script / captcha_script in snake
        // case and jsapiScript in camel case, as 2Captcha does. Accept the other
        // spelling of each, which is what createTask-style callers arrive with.
        'challengeScript' => 'challenge_script',
        'captchaScript' => 'captcha_script',
        'jsapi_script' => 'jsapiScript',
        // DataDome: 2Captcha and the API document captcha_url; createTask-style
        // callers arrive with captchaUrl.
        'captchaUrl' => 'captcha_url',
        'captchaURL' => 'captcha_url',
    ];

    /**
     * Map friendly SDK parameter names onto the raw CapSkip API names.
     *
     * @param array<string, mixed> $params
     *
     * @return array<string, mixed>
     */
    public static function applyParamAliases(array $params): array
    {
        $out = $params;
        foreach (self::PARAM_ALIASES as $alias => $apiName) {
            if (array_key_exists($alias, $out)) {
                if (array_key_exists($apiName, $out) && $out[$alias] !== $out[$apiName]) {
                    throw new ValidationException("Conflicting parameters: '{$alias}' and '{$apiName}'");
                }
                $out[$apiName] = $out[$alias];
                unset($out[$alias]);
            }
        }

        return $out;
    }

    /**
     * Normalize a `proxy` value (array form or bare string) into `proxy` +
     * `proxytype` fields understood by the API.
     *
     * @param array<string, mixed> $params
     *
     * @return array<string, mixed>
     */
    public static function applyProxy(array $params): array
    {
        $out = $params;
        $proxy = $out['proxy'] ?? null;
        unset($out['proxy']);

        if (self::isEmptyProxy($proxy)) {
            return $out;
        }

        if (is_array($proxy)) {
            if (!array_key_exists('uri', $proxy) || !array_key_exists('type', $proxy)) {
                throw new ValidationException("proxy dict must contain 'type' and 'uri' keys");
            }
            $out['proxy'] = $proxy['uri'];
            $out['proxytype'] = $proxy['type'];
        } else {
            $out['proxy'] = $proxy;
            if (!array_key_exists('proxytype', $out)) {
                $out['proxytype'] = 'HTTP';
            }
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $params
     */
    public static function validateNormalSubmit(array $params): void
    {
        $unknown = self::unknownKeys($params, self::NORMAL_SUBMIT);
        if (!empty($unknown)) {
            throw new ValidationException(
                'Unsupported parameters for image captcha: ' . self::reprList($unknown) . '. '
                . 'CapSkip only supports: method, file/body, json.'
            );
        }
        if (array_key_exists('proxy', $params) || array_key_exists('proxytype', $params)) {
            throw new ValidationException(
                'Proxy is not supported for image captcha. '
                . 'Use proxy only with reCAPTCHA or Turnstile.'
            );
        }
    }

    /**
     * @param array<string, mixed> $params
     */
    public static function validateRecaptchaSubmit(array $params, string $version): void
    {
        $normalized = strtolower($version !== '' ? $version : 'v2');

        if ($normalized === 'v3') {
            $allowed = self::RECAPTCHA_V3_SUBMIT;
            if (!empty($params['invisible'])) {
                throw new ValidationException('invisible is only supported for reCAPTCHA v2.');
            }
        } else {
            $allowed = self::RECAPTCHA_V2_SUBMIT;
            if (($params['version'] ?? null) === 'v3') {
                throw new ValidationException("Use version='v3' for reCAPTCHA v3.");
            }
            foreach (['action', 'min_score'] as $key) {
                if (array_key_exists($key, $params)) {
                    throw new ValidationException("'{$key}' is only supported for reCAPTCHA v3.");
                }
            }
        }

        $unknown = self::unknownKeys($params, $allowed);
        if (!empty($unknown)) {
            throw new ValidationException(
                "Unsupported parameters for reCAPTCHA {$normalized}: " . self::reprList($unknown) . '.'
            );
        }
    }

    /**
     * @param array<string, mixed> $params
     */
    public static function validateTurnstileSubmit(array $params): void
    {
        $unknown = self::unknownKeys($params, self::TURNSTILE_SUBMIT);
        if (!empty($unknown)) {
            throw new ValidationException(
                'Unsupported parameters for Turnstile: ' . self::reprList($unknown) . '.'
            );
        }
    }

    /**
     * @param array<string, mixed> $params
     */
    public static function validateGeetestSubmit(array $params): void
    {
        // All three are documented as required. gt is static per site, challenge is
        // single-use and expires in about a minute; without them CapSkip answers
        // ERROR_BAD_PARAMETERS, and without pageurl ERROR_PAGEURL. Fail locally so a
        // missing value does not cost a round-trip.
        foreach (['gt', 'challenge', 'pageurl'] as $key) {
            if (empty($params[$key])) {
                throw new ValidationException("'{$key}' is required for GeeTest v3.");
            }
        }

        $unknown = self::unknownKeys($params, self::GEETEST_SUBMIT);
        if (!empty($unknown)) {
            throw new ValidationException(
                'Unsupported parameters for GeeTest: ' . self::reprList($unknown) . '.'
            );
        }
    }

    /**
     * Drop unset challenge params and serialize an inline challenge document.
     *
     * `altcha($url, ['challenge_url' => ..., 'challenge_json' => ...])` is
     * normally called with one of the two left as null, and the form body can
     * only carry a string -- so a document passed as an array is serialized
     * rather than triggering an array-to-string conversion. Mirrors the server,
     * which reads a JSON-body `null` as "not sent".
     *
     * @param array<string, mixed> $params
     *
     * @return array<string, mixed>
     */
    public static function normalizeAltchaSubmit(array $params): array
    {
        $out = [];
        foreach ($params as $key => $value) {
            if ($value !== null) {
                $out[$key] = $value;
            }
        }

        if (isset($out['challenge_json']) && is_array($out['challenge_json'])) {
            $out['challenge_json'] = (string) json_encode($out['challenge_json']);
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $params
     */
    public static function validateAltchaSubmit(array $params): void
    {
        if (empty($params['pageurl'])) {
            throw new ValidationException("'pageurl' is required for ALTCHA.");
        }

        // CapSkip answers ERROR_BAD_PARAMETERS when neither is sent. Sending both
        // is deliberately allowed -- the inline document simply wins, because
        // fetching would only re-obtain what the caller already supplied.
        if (empty($params['challenge_url']) && empty($params['challenge_json'])) {
            throw new ValidationException(
                "ALTCHA needs a challenge: pass 'challenge_url' for CapSkip to fetch it, "
                . "or 'challenge_json' with the challenge document itself."
            );
        }

        $unknown = self::unknownKeys($params, self::ALTCHA_SUBMIT);
        if (!empty($unknown)) {
            throw new ValidationException(
                'Unsupported parameters for ALTCHA: ' . self::reprList($unknown) . '.'
            );
        }
    }

    /**
     * Drop parameters left as null so an omitted optional is not sent as "".
     *
     * The form body can only carry strings, so a default of null would
     * otherwise reach the server stringified. Mirrors the server, which reads a
     * JSON-body `null` as "not sent".
     *
     * @param array<string, mixed> $params
     *
     * @return array<string, mixed>
     */
    public static function dropUnset(array $params): array
    {
        $out = [];
        foreach ($params as $key => $value) {
            if ($value !== null) {
                $out[$key] = $value;
            }
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $params
     */
    public static function validateCapySubmit(array $params): void
    {
        // Both are documented as required; without captchakey the server answers
        // ERROR_BAD_PARAMETERS and without pageurl ERROR_PAGEURL. Fail locally so
        // a missing value does not cost a round-trip.
        foreach (['captchakey', 'pageurl'] as $key) {
            if (empty($params[$key])) {
                throw new ValidationException("'{$key}' is required for Capy.");
            }
        }

        $version = $params['version'] ?? null;
        if ($version !== null && $version !== ''
            && !in_array(strtolower((string) $version), self::CAPY_VERSIONS, true)
        ) {
            throw new ValidationException(
                "Unsupported Capy version '{$version}'. CapSkip solves the puzzle family "
                . "only -- 'avatar' is a different challenge behind a different endpoint, "
                . 'and the server refuses it rather than returning a puzzle answer the '
                . 'target site would reject.'
            );
        }

        $unknown = self::unknownKeys($params, self::CAPY_SUBMIT);
        if (!empty($unknown)) {
            throw new ValidationException(
                'Unsupported parameters for Capy: ' . self::reprList($unknown) . '.'
            );
        }
    }

    /**
     * @param array<string, mixed> $params
     */
    public static function validateCaptchaFoxSubmit(array $params): void
    {
        foreach (['sitekey', 'pageurl'] as $key) {
            if (empty($params[$key])) {
                throw new ValidationException("'{$key}' is required for CaptchaFox.");
            }
        }

        $unknown = self::unknownKeys($params, self::CAPTCHAFOX_SUBMIT);
        if (!empty($unknown)) {
            throw new ValidationException(
                'Unsupported parameters for CaptchaFox: ' . self::reprList($unknown) . '.'
            );
        }
    }

    /**
     * @param array<string, mixed> $params
     */
    public static function validateFriendlyCaptchaSubmit(array $params): void
    {
        foreach (['sitekey', 'pageurl'] as $key) {
            if (empty($params[$key])) {
                throw new ValidationException("'{$key}' is required for Friendly Captcha.");
            }
        }

        $version = $params['version'] ?? null;
        if ($version !== null && $version !== ''
            && !in_array(strtolower((string) $version), self::FRIENDLY_CAPTCHA_VERSIONS, true)
        ) {
            throw new ValidationException(
                "Unsupported Friendly Captcha version '{$version}'. Use 'v1' or 'v2' "
                . '(a bare 1 or 2 is accepted too). The two are different protocols '
                . 'sharing one sitekey namespace, so solving the wrong one returns a '
                . 'well-formed token the target site rejects.'
            );
        }

        $unknown = self::unknownKeys($params, self::FRIENDLY_CAPTCHA_SUBMIT);
        if (!empty($unknown)) {
            throw new ValidationException(
                'Unsupported parameters for Friendly Captcha: ' . self::reprList($unknown) . '.'
            );
        }
    }

    /**
     * True for a script URL CapSkip will accept on an AWS WAF task.
     *
     * Mirrors the server: https, a host under .awswaf.com, no credentials or
     * explicit port other than 443, and a path ending in challenge.js,
     * captcha.js or jsapi.js. The server only applies the check once the task is
     * queued, so failing here saves a submit that could never solve.
     *
     * @param mixed $url
     */
    public static function isAmazonWafScriptUrl($url): bool
    {
        if (!is_string($url)) {
            return false;
        }
        $parts = parse_url(trim($url));
        if (!is_array($parts)) {
            return false;
        }

        $host = strtolower((string) ($parts['host'] ?? ''));
        $port = $parts['port'] ?? null;

        return strtolower((string) ($parts['scheme'] ?? '')) === 'https'
            && str_ends_with($host, '.awswaf.com')
            && !isset($parts['user'])
            && !isset($parts['pass'])
            && ($port === null || $port === 443)
            && preg_match(self::AMAZON_WAF_SCRIPT_PATH, (string) ($parts['path'] ?? '')) === 1;
    }

    /**
     * @param array<string, mixed> $params
     */
    public static function validateAmazonWafSubmit(array $params): void
    {
        // Without sitekey the server answers ERROR_BAD_PARAMETERS and without
        // pageurl ERROR_PAGEURL. Fail locally so a missing value costs no
        // round-trip.
        foreach (['sitekey', 'pageurl'] as $key) {
            if (empty($params[$key])) {
                throw new ValidationException("'{$key}' is required for Amazon WAF.");
            }
        }

        $pageurl = trim((string) $params['pageurl']);
        $scheme = strtolower((string) (parse_url($pageurl, PHP_URL_SCHEME) ?? ''));
        if (!in_array($scheme, ['http', 'https'], true) || !str_contains($pageurl, '://')) {
            throw new ValidationException(
                "'pageurl' must be a full http or https URL for Amazon WAF, got '{$pageurl}'."
            );
        }

        // iv and context come off the same challenge page and only work as a
        // pair; the server refuses one without the other with
        // ERROR_BAD_PARAMETERS.
        if (empty($params['iv']) !== empty($params['context'])) {
            throw new ValidationException(
                "Amazon WAF 'iv' and 'context' go together: send both (a challenge "
                . 'page) or neither (a widget page, or let CapSkip read them from pageurl).'
            );
        }

        foreach (self::AMAZON_WAF_SCRIPTS as $key) {
            $value = $params[$key] ?? null;
            if (!empty($value) && !self::isAmazonWafScriptUrl($value)) {
                $shown = is_scalar($value) ? (string) $value : gettype($value);
                throw new ValidationException(
                    "'{$key}' must be the https URL of an AWS WAF script on an "
                    . "awswaf.com host (challenge.js, captcha.js or jsapi.js), got '{$shown}'. "
                    . 'CapSkip refuses any other script, because it is not an AWS WAF integration.'
                );
            }
        }

        $unknown = self::unknownKeys($params, self::AMAZON_WAF_SUBMIT);
        if (!empty($unknown)) {
            throw new ValidationException(
                'Unsupported parameters for Amazon WAF: ' . self::reprList($unknown) . '.'
            );
        }
    }

    /** True for captcha-delivery.com itself or any subdomain of it. */
    private static function isDataDomeHost(string $host): bool
    {
        $host = strtolower($host);

        return $host === self::DATADOME_CHALLENGE_DOMAIN
            || str_ends_with($host, '.' . self::DATADOME_CHALLENGE_DOMAIN);
    }

    /**
     * The query of a DataDome challenge URL as an array, or null if it is not one.
     *
     * Mirrors the server: an https URL on captcha-delivery.com (or a
     * subdomain), path /captcha/ or /interstitial/, carrying initialCid, hash
     * and cid. An iframe src copied HTML-escaped (&amp;) and a scheme-relative
     * //geo... URL are both read the way the server reads them.
     *
     * @param mixed $url
     *
     * @return array<string, mixed>|null
     */
    public static function parseDataDomeCaptchaUrl($url): ?array
    {
        if (!is_scalar($url)) {
            return null;
        }
        $text = html_entity_decode(trim((string) $url), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        if (str_starts_with($text, '//')) {
            $text = 'https:' . $text;
        }
        $parts = parse_url($text);
        if (!is_array($parts)) {
            return null;
        }
        if (strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
            || !self::isDataDomeHost((string) ($parts['host'] ?? ''))
        ) {
            return null;
        }
        $path = strtolower(rtrim((string) ($parts['path'] ?? ''), '/'));
        if (!in_array($path, self::DATADOME_CHALLENGE_PATHS, true)) {
            return null;
        }
        $query = [];
        parse_str((string) ($parts['query'] ?? ''), $query);
        foreach (['initialCid', 'hash', 'cid'] as $key) {
            if (!isset($query[$key]) || !is_string($query[$key]) || $query[$key] === '') {
                return null;
            }
        }

        return $query;
    }

    /**
     * True for a stock Chrome-for-Windows User-Agent, the only kind CapSkip takes.
     *
     * DataDome ties the cookie to the browser that earned it, and CapSkip
     * solves in Chrome on Windows, so a cookie for any other User-Agent would be
     * refused on replay. The server also requires the same Chrome major version
     * as its own browser; that part can only be checked there.
     *
     * @param mixed $userAgent
     */
    public static function isDataDomeUserAgent($userAgent): bool
    {
        if (!is_scalar($userAgent)) {
            return false;
        }
        $ua = (string) $userAgent;
        if (!str_contains($ua, 'Windows NT ') || preg_match(self::CHROME_VERSION, $ua) !== 1) {
            return false;
        }
        foreach (self::NOT_STOCK_WINDOWS_CHROME as $token) {
            if (str_contains($ua, $token)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array<string, mixed> $params
     */
    public static function validateDataDomeSubmit(array $params): void
    {
        // Without captcha_url the server answers ERROR_BAD_PARAMETERS and
        // without pageurl ERROR_PAGEURL. Fail locally so a missing value costs
        // no round-trip.
        foreach (['captcha_url', 'pageurl'] as $key) {
            if (empty($params[$key])) {
                throw new ValidationException("'{$key}' is required for DataDome.");
            }
        }

        $captchaUrl = $params['captcha_url'];
        $query = self::parseDataDomeCaptchaUrl($captchaUrl);
        if ($query === null) {
            $shown = is_scalar($captchaUrl) ? (string) $captchaUrl : gettype($captchaUrl);
            throw new ValidationException(
                "'captcha_url' must be the https address of a DataDome challenge on "
                . 'captcha-delivery.com, under /captcha/ or /interstitial/ and carrying '
                . "initialCid, hash and cid, got '{$shown}'. Take it from the block "
                . "page's iframe src, or build it from the page's `var dd` object."
            );
        }
        // t=bv is DataDome saying the address that fetched the block page is
        // banned. Nothing sent from it can pass, and the server refuses the
        // task with ERROR_PROXY_BANNED, so say so before the round-trip.
        if (($query['t'] ?? null) === 'bv') {
            throw new ValidationException(
                'This DataDome block page bans the IP address that requested it '
                . '(t=bv), so no solve from that address can pass. Change the address '
                . '(proxy), request the page again and submit the new captcha_url.'
            );
        }

        $pageurl = trim(is_scalar($params['pageurl']) ? (string) $params['pageurl'] : '');
        $page = parse_url($pageurl);
        if (!is_array($page)
            || !in_array(strtolower((string) ($page['scheme'] ?? '')), ['http', 'https'], true)
            || (string) ($page['host'] ?? '') === ''
            || self::isDataDomeHost((string) $page['host'])
        ) {
            throw new ValidationException(
                "'pageurl' must be the full http or https URL of the page that "
                . "returned the DataDome block page, got '{$pageurl}'."
            );
        }

        $userAgent = $params['useragent'] ?? null;
        if ($userAgent !== null && $userAgent !== '' && !self::isDataDomeUserAgent($userAgent)) {
            $shown = is_scalar($userAgent) ? (string) $userAgent : gettype($userAgent);
            throw new ValidationException(
                "DataDome 'userAgent' must be a stock Chrome for Windows User-Agent "
                . 'with the same major version as the browser CapSkip solves with, got '
                . "'{$shown}'. The cookie only works with the User-Agent that earned "
                . "it. Leave it out and CapSkip uses its own browser's, which the "
                . 'result reports as `userAgent`.'
            );
        }

        $unknown = self::unknownKeys($params, self::DATADOME_SUBMIT);
        if (!empty($unknown)) {
            throw new ValidationException(
                'Unsupported parameters for DataDome: ' . self::reprList($unknown) . '.'
            );
        }
    }

    /**
     * Drop unset optionals and send `invisible` as the 1 the server reads.
     *
     * The server reads "1" or "true" as an invisible widget and defaults to
     * visible. A PHP bool would reach the form body as "1" or "0" and a string
     * as typed, so every accepted spelling is settled here: on is sent as 1,
     * off is not sent at all, and anything else is refused.
     *
     * @param array<string, mixed> $params
     *
     * @return array<string, mixed>
     */
    public static function normalizeHCaptchaSubmit(array $params): array
    {
        $params = array_filter(
            self::dropUnset($params),
            static fn ($value) => $value !== ''
        );

        if (array_key_exists('invisible', $params)) {
            $on = self::hCaptchaInvisible($params['invisible']);
            if ($on === null) {
                $raw = $params['invisible'];
                $shown = is_scalar($raw) ? var_export($raw, true) : gettype($raw);
                throw new ValidationException(
                    "invisible must be 0 or 1 (or a bool) for hCaptcha, not {$shown}."
                );
            }
            if ($on) {
                $params['invisible'] = 1;
            } else {
                unset($params['invisible']);
            }
        }

        return $params;
    }

    /**
     * True / false for an accepted `invisible` value, null for anything else.
     *
     * @param mixed $value
     */
    private static function hCaptchaInvisible($value): ?bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_int($value)) {
            return $value === 1 ? true : ($value === 0 ? false : null);
        }
        if (is_string($value)) {
            $text = strtolower($value);
            if ($text === '1' || $text === 'true') {
                return true;
            }
            if ($text === '0' || $text === 'false') {
                return false;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $params
     */
    public static function validateHCaptchaSubmit(array $params): void
    {
        // The server answers ERROR_RECAPTCHA_INVALID_SITEKEY for a missing
        // sitekey and ERROR_PAGEURL for a page URL that is missing or not
        // http(s). Fail locally so neither costs a round-trip.
        $sitekey = $params['sitekey'] ?? null;
        if (!is_scalar($sitekey) || trim((string) $sitekey) === '') {
            throw new ValidationException("'sitekey' is required for hCaptcha.");
        }

        $rawPageurl = $params['pageurl'] ?? null;
        $pageurl = is_scalar($rawPageurl) ? trim((string) $rawPageurl) : '';
        if ($pageurl === '') {
            throw new ValidationException("'pageurl' is required for hCaptcha.");
        }
        $page = parse_url($pageurl);
        if (!is_array($page)
            || !in_array(strtolower((string) ($page['scheme'] ?? '')), ['http', 'https'], true)
            || (string) ($page['host'] ?? '') === ''
        ) {
            throw new ValidationException(
                "'pageurl' must be an absolute http or https URL for hCaptcha, got '{$pageurl}'."
            );
        }

        $unknown = self::unknownKeys($params, self::HCAPTCHA_SUBMIT);
        if (!empty($unknown)) {
            throw new ValidationException(
                'Unsupported parameters for hCaptcha: ' . self::reprList($unknown) . '.'
            );
        }
    }

    /**
     * Apply aliases + proxy normalization, then validate for the captcha type.
     *
     * @param array<string, mixed> $params
     *
     * @return array<string, mixed>
     */
    public static function prepareSubmitParams(array $params, string $captchaType, string $version = 'v2'): array
    {
        $params = self::applyParamAliases($params);
        $params = self::applyProxy($params);

        if ($captchaType === 'normal') {
            self::validateNormalSubmit($params);
        } elseif ($captchaType === 'recaptcha') {
            self::validateRecaptchaSubmit($params, $version);
        } elseif ($captchaType === 'turnstile') {
            self::validateTurnstileSubmit($params);
        } elseif ($captchaType === 'geetest') {
            self::validateGeetestSubmit($params);
        } elseif ($captchaType === 'altcha') {
            $params = self::normalizeAltchaSubmit($params);
            self::validateAltchaSubmit($params);
        } elseif ($captchaType === 'capy') {
            $params = self::dropUnset($params);
            self::validateCapySubmit($params);
        } elseif ($captchaType === 'captchafox') {
            $params = self::dropUnset($params);
            self::validateCaptchaFoxSubmit($params);
        } elseif ($captchaType === 'friendly_captcha') {
            $params = self::dropUnset($params);
            self::validateFriendlyCaptchaSubmit($params);
        } elseif ($captchaType === 'amazon_waf') {
            // An optional left as '' means "not sent" to the server, exactly
            // like null, so it is dropped rather than tripping the iv/context
            // pairing.
            $params = array_filter(
                self::dropUnset($params),
                static fn ($value) => $value !== ''
            );
            self::validateAmazonWafSubmit($params);
        } elseif ($captchaType === 'datadome') {
            // As for AWS WAF, an optional left as '' means "not sent".
            $params = array_filter(
                self::dropUnset($params),
                static fn ($value) => $value !== ''
            );
            self::validateDataDomeSubmit($params);
        } elseif ($captchaType === 'hcaptcha') {
            $params = self::normalizeHCaptchaSubmit($params);
            self::validateHCaptchaSubmit($params);
        }

        // Skipped for 'normal', which rejects proxy outright with a clearer message.
        if ($captchaType !== 'normal') {
            self::validateProxyType($params);
        }

        return $params;
    }

    /**
     * @param array<string, mixed> $params
     */
    public static function validateProxyType(array $params): void
    {
        $proxytype = $params['proxytype'] ?? null;
        if ($proxytype === null || $proxytype === '') {
            return;
        }

        if (!in_array(strtoupper((string) $proxytype), self::PROXY_TYPES, true)) {
            throw new ValidationException(
                "Unsupported proxytype '{$proxytype}'. "
                . 'CapSkip accepts: ' . implode(', ', self::PROXY_TYPES) . '.'
            );
        }
    }

    /**
     * Format a list the way Python's `sorted(...)` repr does, for message parity.
     *
     * @param array<int, string> $values
     */
    public static function reprList(array $values): string
    {
        $quoted = array_map(static fn ($value) => "'{$value}'", $values);

        return '[' . implode(', ', $quoted) . ']';
    }

    /** @param mixed $proxy */
    private static function isEmptyProxy($proxy): bool
    {
        if ($proxy === null || $proxy === '' || $proxy === false || $proxy === 0) {
            return true;
        }

        return is_array($proxy) && count($proxy) === 0;
    }

    /**
     * @param array<string, mixed> $params
     * @param array<int, string>   $allowed
     *
     * @return array<int, string>
     */
    private static function unknownKeys(array $params, array $allowed): array
    {
        $excluded = array_merge($allowed, ['key', 'file', 'files']);
        $unknown = array_values(array_diff(array_keys($params), $excluded));
        sort($unknown);

        return $unknown;
    }
}

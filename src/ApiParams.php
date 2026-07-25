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

<?php

declare(strict_types=1);

namespace CapSkip;

use CapSkip\Exceptions\ApiException;
use CapSkip\Exceptions\CapSkipError;
use CapSkip\Exceptions\NetworkException;
use CapSkip\Exceptions\TimeoutException;
use CapSkip\Exceptions\ValidationException;

/** Client for the CapSkip local captcha solver (image, reCAPTCHA, Turnstile). */
class CapSkip
{
    /** Installed SDK version. */
    public const VERSION = '1.0.1';

    /**
     * First poll fires this soon after submitting (in seconds), then the interval
     * backs off (doubling) up to the configured pollingInterval ceiling. Keeps
     * latency low for fast local solves (e.g. image captchas) without hammering on
     * slow ones.
     */
    public const INITIAL_POLLING_INTERVAL = 0.25;

    public string $apiKey;
    /** @var int|float */
    public $defaultTimeout;
    /** @var int|float */
    public $recaptchaTimeout;
    /** @var int|float */
    public $pollingInterval;
    public ApiClient $apiClient;
    /** @var class-string<CapSkipError> */
    public string $exceptions;

    /**
     * @param array{
     *     apiKey?: string,
     *     host?: string,
     *     port?: int,
     *     defaultTimeout?: int|float,
     *     recaptchaTimeout?: int|float,
     *     pollingInterval?: int|float
     * } $options
     */
    public function __construct(array $options = [])
    {
        $this->apiKey = $options['apiKey'] ?? 'capskip';
        $this->defaultTimeout = $options['defaultTimeout'] ?? 120;
        $this->recaptchaTimeout = $options['recaptchaTimeout'] ?? 300;
        $this->pollingInterval = $options['pollingInterval'] ?? 5;
        $this->apiClient = new ApiClient([
            'host' => $options['host'] ?? '127.0.0.1',
            'port' => $options['port'] ?? 8080,
        ]);
        $this->exceptions = CapSkipError::class;
    }

    /**
     * Solve an image captcha from a file path, URL, base64 string, or data-URI.
     *
     * @param array{json?: int} $options Only `json` is accepted besides the image input.
     *
     * @return array<string, mixed>
     */
    public function normal(string $file, array $options = []): array
    {
        $unsupported = array_values(array_diff(array_keys($options), ['json']));
        sort($unsupported);
        if (!empty($unsupported)) {
            throw new ValidationException(
                'Unsupported parameters for image captcha: ' . ApiParams::reprList($unsupported) . '. '
                . 'Only json is supported besides the image input.'
            );
        }

        return $this->solve(array_merge($this->getMethod($file), $options));
    }

    /**
     * Solve reCAPTCHA v2/v3 (invisible, enterprise, proxy).
     *
     * @param array<string, mixed> $options `version`, `enterprise`, `invisible`,
     *                                       `action`, `score`, `proxy`, ...
     *
     * @return array<string, mixed>
     */
    public function recaptcha(string $sitekey, string $url, array $options = []): array
    {
        $version = $options['version'] ?? 'v2';
        $enterprise = $options['enterprise'] ?? 0;
        unset($options['version'], $options['enterprise']);

        $params = array_merge([
            'googlekey' => $sitekey,
            'url' => $url,
            'method' => 'userrecaptcha',
            'enterprise' => $enterprise,
        ], $options);

        if (strtolower((string) $version) === 'v3') {
            $params['version'] = 'v3';
        }

        return $this->solve(array_merge(['timeout' => $this->recaptchaTimeout], $params));
    }

    /**
     * Solve Cloudflare Turnstile (widget or challenge page).
     *
     * @param array<string, mixed> $options `action`, `data`, `pagedata`, `proxy`, ...
     *
     * @return array<string, mixed>
     */
    public function turnstile(string $sitekey, string $url, array $options = []): array
    {
        return $this->solve(array_merge(
            ['sitekey' => $sitekey, 'url' => $url],
            $options,
            ['method' => 'turnstile', 'poll_json' => 1]
        ));
    }

    /**
     * Submit then poll to completion. Used by the higher-level solve methods.
     *
     * @param array<string, mixed> $options Submit params plus optional `timeout`,
     *                                       `polling_interval`, and `poll_json`.
     *
     * @return array<string, mixed>
     */
    public function solve(array $options = []): array
    {
        $timeout = $options['timeout'] ?? 0;
        $pollingInterval = $options['polling_interval'] ?? 0;
        $pollJson = (int) ($options['poll_json'] ?? 0);
        unset($options['timeout'], $options['polling_interval'], $options['poll_json']);

        $captchaId = $this->send($options);
        $result = ['captchaId' => $captchaId];
        $solveTimeout = (float) ($timeout ?: $this->defaultTimeout);
        $sleep = (float) ($pollingInterval ?: $this->pollingInterval);
        $polled = $this->waitResult($captchaId, $solveTimeout, $sleep, $pollJson);

        return self::applyPollResult($result, $polled);
    }

    /**
     * Poll until solved or the timeout (seconds) elapses.
     *
     * @return array<string, mixed>|string
     */
    public function waitResult(string $id, float $timeout, float $pollingInterval, int $json = 0)
    {
        $deadline = microtime(true) + $timeout;
        $interval = min(self::INITIAL_POLLING_INTERVAL, $pollingInterval);
        while (microtime(true) < $deadline) {
            try {
                return $this->getResult($id, $json);
            } catch (NetworkException $e) {
                usleep((int) round($interval * 1_000_000));
                $interval = self::nextPollInterval($interval, $pollingInterval);
            }
        }

        throw new TimeoutException("timeout {$timeout} exceeded");
    }

    /**
     * Resolve an image input into the `in.php` `method`/`body`/`file` fields.
     *
     * @return array<string, string>
     */
    public function getMethod(string $file): array
    {
        if ($file === '') {
            throw new ValidationException('File required');
        }
        if (str_starts_with($file, 'data:')) {
            $comma = strpos($file, ',');

            return ['method' => 'base64', 'body' => $comma === false ? $file : substr($file, $comma + 1)];
        }
        if (!str_contains($file, '.') && strlen($file) > 50) {
            return ['method' => 'base64', 'body' => $file];
        }
        if (str_starts_with($file, 'http')) {
            $resp = Http::get($file);
            if ($resp['status'] !== 200) {
                throw new ValidationException("File could not be downloaded from url: {$file}");
            }

            return ['method' => 'base64', 'body' => base64_encode($resp['body'])];
        }
        if (!file_exists($file)) {
            throw new ValidationException("File not found: {$file}");
        }

        return ['method' => 'post', 'file' => $file];
    }

    /**
     * Submit a captcha without polling; returns the captcha id.
     *
     * @param array<string, mixed> $params
     */
    public function send(array $params = []): string
    {
        $prepared = $this->prepareSendParams(array_merge($params, ['key' => $this->apiKey]));
        $files = $prepared['files'] ?? [];
        unset($prepared['files']);
        $response = $this->apiClient->in_(array_merge(['files' => $files], $prepared));

        return self::parseSubmitResponse($response);
    }

    /**
     * Extract the captcha id from an `in.php` response. CapSkip replies with
     * `OK|<id>` by default, or `{"status":1,"request":"<id>"}` when the submit
     * carried `json=1`; both forms are accepted.
     */
    public static function parseSubmitResponse(string $response): string
    {
        $text = trim($response);

        if (str_starts_with($text, 'OK|')) {
            return substr($text, 3);
        }

        $data = json_decode($text, true);
        if (is_array($data) && ($data['status'] ?? null) === 1 && isset($data['request'])) {
            return (string) $data['request'];
        }

        throw new ApiException("cannot recognize response {$response}");
    }

    /**
     * Poll a result once; raises `NetworkException` while not ready.
     *
     * @return array<string, mixed>|string
     */
    public function getResult(string $id, int $json = 0)
    {
        $query = ['key' => $this->apiKey, 'action' => 'get', 'id' => $id];
        if ($json) {
            $query['json'] = 1;
        }

        return self::parsePollResponse($this->apiClient->res($query), $json ? 1 : 0);
    }

    /**
     * Parse a raw `res.php` body into a solution string (or the decoded JSON
     * payload when `json=1`). Raises `NetworkException` while the answer is not
     * ready, so callers keep polling.
     *
     * @param string|null $response
     *
     * @return array<string, mixed>|string
     */
    public static function parsePollResponse($response, int $jsonMode = 0)
    {
        $text = trim((string) ($response ?? ''));

        // CapSkip returns an empty body whenever no result is available yet:
        // briefly right after submit (before it starts reporting CAPCHA_NOT_READY),
        // for an unknown id, and after a solved token has already been read once.
        // Treat it like CAPCHA_NOT_READY so the caller keeps polling.
        if ($text === '') {
            throw new NetworkException();
        }

        if ($jsonMode) {
            $data = json_decode($text, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new ApiException("invalid JSON response: {$response}");
            }

            if (is_array($data) && ($data['status'] ?? null) === 0 && ($data['request'] ?? null) === 'CAPCHA_NOT_READY') {
                throw new NetworkException();
            }

            if (!is_array($data) || ($data['status'] ?? null) !== 1) {
                throw new ApiException('cannot recognize response ' . json_encode($data));
            }

            return $data;
        }

        if ($text === 'CAPCHA_NOT_READY') {
            throw new NetworkException();
        }

        if (!str_starts_with($text, 'OK|')) {
            throw new ApiException("cannot recognize response {$response}");
        }

        return substr($text, 3);
    }

    public static function nextPollInterval(float $interval, float $ceiling): float
    {
        return min($interval * 2, $ceiling);
    }

    /**
     * @param array<string, mixed>          $result
     * @param array<string, mixed>|string   $polled
     *
     * @return array<string, mixed>
     */
    public static function applyPollResult(array $result, $polled): array
    {
        if (is_array($polled)) {
            $result['code'] = $polled['request'] ?? '';
            $userAgent = $polled['useragent'] ?? ($polled['userAgent'] ?? null);
            if ($userAgent) {
                $result['userAgent'] = $userAgent;
            }
        } else {
            $result['code'] = $polled;
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return array<string, mixed>
     */
    private function prepareSendParams(array $params): array
    {
        $method = $params['method'] ?? null;
        if ($method === 'post' || $method === 'base64') {
            return ApiParams::prepareSubmitParams($params, 'normal');
        }
        if ($method === 'userrecaptcha') {
            return ApiParams::prepareSubmitParams($params, 'recaptcha', (string) ($params['version'] ?? 'v2'));
        }
        if ($method === 'turnstile') {
            return ApiParams::prepareSubmitParams($params, 'turnstile');
        }

        return ApiParams::applyProxy(ApiParams::applyParamAliases($params));
    }
}

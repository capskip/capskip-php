<?php

declare(strict_types=1);

namespace CapSkip;

use CapSkip\Exceptions\ApiException;
use CapSkip\Exceptions\CapSkipError;
use CapSkip\Exceptions\NetworkException;
use CapSkip\Exceptions\TimeoutException;
use CapSkip\Exceptions\ValidationException;

/** Client for the CapSkip local captcha solver (image, reCAPTCHA, Turnstile, GeeTest v3). */
class CapSkip
{
    /** Installed SDK version. */
    public const VERSION = '1.2.0';

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
     * Solve a GeeTest v3 slider.
     *
     * `$gt` is static per site; `$challenge` is single-use and expires in about a
     * minute, so fetch a fresh pair immediately before calling this. Pass
     * `api_server` when the site uses a non-default GeeTest API server domain.
     *
     * The result carries the raw answer as `code` (a JSON string) plus the parsed
     * `challenge`, `validate`, and `seccode` fields to post back to the target site.
     *
     * @param array<string, mixed> $options `api_server`, `proxy`, ...
     *
     * @return array<string, mixed>
     */
    public function geetest(string $gt, string $challenge, string $url, array $options = []): array
    {
        // Like reCAPTCHA, this is a real browser solve (load, slide, verify) and
        // can retry internally, so it gets the longer of the two timeouts unless
        // the caller asked for a specific one.
        $result = $this->solve(array_merge(
            ['timeout' => $this->recaptchaTimeout, 'gt' => $gt, 'challenge' => $challenge, 'url' => $url],
            $options,
            ['method' => 'geetest', 'poll_json' => 1]
        ));

        return self::applyGeetestSolution($result);
    }

    /**
     * Solve an ALTCHA proof-of-work challenge.
     *
     * Pass `challenge_url` for CapSkip to fetch the challenge itself, or
     * `challenge_json` with the document you already have (a JSON string, or an
     * array which is serialized for you). Sending both is allowed -- the inline
     * document wins. A proxy applies only to the `challenge_url` fetch.
     *
     * Challenges expire fast -- some sites inside two minutes -- and an expired
     * one is refused with a bare "verification failed" that looks exactly like a
     * wrong answer. Fetch the challenge immediately before calling, and post the
     * token promptly.
     *
     * The result carries the raw answer as `code`, the same string as `token`
     * (what the site's `altcha` form field expects, verbatim), and the counter
     * that solved it as `number`.
     *
     * @param array<string, mixed> $options `challenge_url`, `challenge_json`, `proxy`, ...
     *
     * @return array<string, mixed>
     */
    public function altcha(string $url, array $options = []): array
    {
        // An unset challenge param is dropped rather than sent as null, so
        // passing both keys with one left out works.
        $given = [];
        foreach ($options as $key => $value) {
            if ($value !== null) {
                $given[$key] = $value;
            }
        }

        // Unlike GeeTest and reCAPTCHA this is CPU proof-of-work measured in
        // milliseconds, not a browser solve, so it keeps the default timeout.
        $result = $this->solve(array_merge(
            ['url' => $url],
            $given,
            ['method' => 'altcha', 'poll_json' => 1]
        ));

        return self::applyAltchaSolution($result);
    }

    /**
     * Solve a Capy Puzzle captcha.
     *
     * `$sitekey` is the site's public Capy key, conventionally prefixed
     * `PUZZLE_`; it is sent as the `captchakey` the API documents. Pass
     * `api_server` when the widget script points somewhere other than
     * `https://jp.api.capy.me`.
     *
     * The result is not a token. It carries `captchakey`, `challengekey` and
     * `answer`, which go into the target form's `capy_captchakey`,
     * `capy_challengekey` and `capy_answer` fields, plus the raw answer as
     * `code`. Submit `answer` verbatim -- it is the drag path the widget would
     * have recorded, so trimming or re-encoding it invalidates the solve.
     *
     * The challenge key is single-use and short-lived, so submit promptly rather
     * than caching the three values for a later request.
     *
     * @param array<string, mixed> $options `api_server`, `version`, `proxy`, ...
     *
     * @return array<string, mixed>
     */
    public function capy(string $sitekey, string $url, array $options = []): array
    {
        // An unset optional is dropped rather than sent as null, so passing a
        // key with no value behaves as if it were omitted.
        $given = [];
        foreach ($options as $key => $value) {
            if ($value !== null) {
                $given[$key] = $value;
            }
        }

        // A Capy solve is one HTTP fetch plus pixel math, not a browser session,
        // so it keeps the default timeout. It is held back to roughly two seconds
        // before the answer is released -- Capy refuses answers that arrive
        // faster than a human could have produced them -- which the default
        // absorbs.
        $result = $this->solve(array_merge(
            ['captchakey' => $sitekey, 'url' => $url],
            $given,
            ['method' => 'capy', 'poll_json' => 1]
        ));

        return self::applyCapySolution($result);
    }

    /**
     * Solve a CaptchaFox challenge.
     *
     * `$sitekey` is the public key the widget renders with, conventionally
     * prefixed `sk_`, and `$url` has to be the page the widget actually runs on:
     * CaptchaFox checks it against the domains the key is registered for and
     * refuses a mismatch permanently rather than intermittently.
     *
     * Pass `api_server` only when the target page does not load the default
     * widget. A page loading the MAM package expects a `MAM_` prefixed token, and
     * sending the wrong source still succeeds -- it just returns a token in a
     * format the site will not accept, which reads as a silent verification
     * failure rather than an error.
     *
     * The result carries the token as both `code` and `token`, for the form's
     * `cf-captcha-response` field, and `userAgent` when the solve reported one.
     * That User-Agent is the browser's own, not any you sent, so submit the token
     * under it.
     *
     * @param array<string, mixed> $options `api_server`, `useragent`, `proxy`, ...
     *
     * @return array<string, mixed>
     */
    public function captchafox(string $sitekey, string $url, array $options = []): array
    {
        $given = [];
        foreach ($options as $key => $value) {
            if ($value !== null) {
                $given[$key] = $value;
            }
        }

        // A real browser session, like reCAPTCHA and GeeTest, and longer again
        // when an interactive challenge is drawn -- so it gets the longer of the
        // two timeouts unless the caller asked for a specific one.
        $result = $this->solve(array_merge(
            ['timeout' => $this->recaptchaTimeout, 'sitekey' => $sitekey, 'url' => $url],
            $given,
            ['method' => 'captchafox', 'poll_json' => 1]
        ));

        return self::applyTokenSolution($result);
    }

    /**
     * Solve a Friendly Captcha proof-of-work challenge.
     *
     * Two different protocols ship under this name and a sitekey does not tell
     * you which one a site uses, so say which: pass `version` as `v1` or `v2`, or
     * pass `module_script` with the src of the widget's `type="module"` script
     * tag and let CapSkip read the version off the build the site actually loads.
     * With neither, v1 is assumed. Solving the wrong version returns a
     * well-formed token the target site rejects, with nothing to indicate the
     * version was the problem.
     *
     * Pass `api_server` as `eu` for a sitekey on the EU data-residency tenant;
     * both tenants mint a token for the same sitekey, so the wrong one is only
     * caught by the site's own verification.
     *
     * The result carries the token as both `code` and `token`. It goes into
     * `frc-captcha-solution` on v1 and `frc-captcha-response` on v2 -- the field
     * names differ, which is what catches an integration moved from one to the
     * other. A v2 token is roughly six kilobytes, so size whatever carries it
     * accordingly.
     *
     * @param array<string, mixed> $options `version`, `module_script`, `api_server`, ...
     *
     * @return array<string, mixed>
     */
    public function friendlyCaptcha(string $sitekey, string $url, array $options = []): array
    {
        $given = [];
        foreach ($options as $key => $value) {
            if ($value !== null) {
                $given[$key] = $value;
            }
        }

        // Proof-of-work, but not the millisecond kind ALTCHA does: the service
        // sets the difficulty per request and raises it for addresses it has seen
        // a lot of, and v2 always solves in a browser. Both make solve time
        // variable enough to want the longer timeout.
        $result = $this->solve(array_merge(
            ['timeout' => $this->recaptchaTimeout, 'sitekey' => $sitekey, 'url' => $url],
            $given,
            ['method' => 'friendly_captcha', 'poll_json' => 1]
        ));

        return self::applyTokenSolution($result);
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
     * GeeTest answers come back as a JSON string in `request`, keyed with the
     * `geetest_` prefix that the target site's own form fields use. Expand them
     * into `challenge` / `validate` / `seccode`.
     *
     * `code` keeps the raw JSON string so callers that forward it verbatim (or
     * that were written against another solver's API) keep working. If it does
     * not parse, the result is returned untouched rather than masking the
     * server's reply.
     *
     * @param array<string, mixed> $result
     *
     * @return array<string, mixed>
     */
    public static function applyGeetestSolution(array $result): array
    {
        $payload = json_decode((string) ($result['code'] ?? ''), true);
        if (!is_array($payload)) {
            return $result;
        }

        $fields = [
            'challenge' => 'geetest_challenge',
            'validate' => 'geetest_validate',
            'seccode' => 'geetest_seccode',
        ];
        foreach ($fields as $short => $prefixed) {
            $value = $payload[$prefixed] ?? ($payload[$short] ?? null);
            if ($value !== null) {
                $result[$short] = $value;
            }
        }

        return $result;
    }

    /**
     * ALTCHA answers come back as a base64 payload: the challenge document with
     * the winning counter added. That payload is what the site's own `altcha`
     * form field carries, so it is posted back verbatim.
     *
     * Expose it as `token`, and the counter as `number`. `code` keeps the raw
     * answer so callers that forward it verbatim (or that were written against
     * another solver's API) keep working.
     *
     * The counter comes from the server's own `solution` object when the poll
     * carried one, because that is the single field both ALTCHA generations
     * report the same way. Only if it is absent -- a plain-text poll -- is it dug
     * out of the token, which is shaped differently per scheme. If neither yields
     * one, the result keeps its token and simply has no `number`, rather than
     * masking the server's reply.
     *
     * @param array<string, mixed> $result
     *
     * @return array<string, mixed>
     */
    public static function applyAltchaSolution(array $result): array
    {
        $code = (string) ($result['code'] ?? '');
        $result['token'] = $code;

        $solution = $result['solution'] ?? null;
        unset($result['solution']);

        $number = is_array($solution) ? ($solution['number'] ?? null) : null;
        if ($number === null) {
            $number = self::altchaTokenCounter($code);
        }

        if ($number !== null) {
            $result['number'] = $number;
        }

        return $result;
    }

    /**
     * A Capy solution is not a token. It is three values that together go into
     * the target form, under the `capy_` prefixed names the widget would have
     * filled in -- expand them into `captchakey` / `challengekey` / `answer`.
     *
     * `code` keeps the raw answer -- an array when polled with json=1, where the
     * server puts the object straight into `request`, or the JSON string it sends
     * after `OK|` in plain-text mode -- so callers that forward it verbatim (or
     * that were written against another solver's API) keep working.
     *
     * If the answer does not parse, the result is returned untouched rather than
     * masking the server's reply.
     *
     * @param array<string, mixed> $result
     *
     * @return array<string, mixed>
     */
    public static function applyCapySolution(array $result): array
    {
        $payload = $result['solution'] ?? null;
        unset($result['solution']);

        if (!is_array($payload)) {
            $code = $result['code'] ?? null;
            if (is_array($code)) {
                $payload = $code;
            } else {
                $payload = json_decode((string) $code, true);
            }
        }

        if (!is_array($payload)) {
            return $result;
        }

        foreach (['captchakey', 'challengekey', 'answer', 'respKey'] as $field) {
            if (array_key_exists($field, $payload)) {
                $result[$field] = $payload[$field];
            }
        }

        return $result;
    }

    /**
     * Expose a single-token answer as `token`, named for the form field it fills.
     *
     * `code` keeps the raw answer so callers that forward it verbatim keep
     * working; `token` is the same string. The server's createTask-shaped
     * `solution` object carries that same string, so it is consumed here rather
     * than handed back as a second copy -- but it is read first when `request`
     * came through empty, so a client is never left without the token the poll
     * actually carried.
     *
     * @param array<string, mixed> $result
     *
     * @return array<string, mixed>
     */
    public static function applyTokenSolution(array $result): array
    {
        $solution = $result['solution'] ?? null;
        unset($result['solution']);

        $code = (string) ($result['code'] ?? '');

        if ($code === '' && is_array($solution)) {
            $code = (string) ($solution['token'] ?? '');
            $result['code'] = $code;
        }

        $result['token'] = $code;

        return $result;
    }

    /**
     * Dig the winning counter out of a token, whichever scheme produced it.
     *
     * The two ALTCHA generations nest it differently: a legacy payload is the
     * challenge document with a top-level `number` added, while a proof-of-work
     * v2 payload is `{"challenge": {...}, "solution": {"counter": N, ...}}` and
     * has no `number` at all. Returns null if the payload does not decode.
     *
     * @return mixed
     */
    private static function altchaTokenCounter(string $code)
    {
        // strict mode: reject anything that is not genuinely base64 rather than
        // silently decoding garbage.
        $decoded = base64_decode($code, true);
        if ($decoded === false) {
            return null;
        }

        $payload = json_decode($decoded, true);
        if (!is_array($payload)) {
            return null;
        }

        if (array_key_exists('number', $payload)) {
            return $payload['number'];
        }

        if (isset($payload['solution']) && is_array($payload['solution'])) {
            return $payload['solution']['counter'] ?? null;
        }

        return null;
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
            // ALTCHA's createTask-shaped `solution` object. Carried through so
            // applyAltchaSolution can read the counter the server already worked
            // out, which is the only reliable source for a proof-of-work v2
            // answer; that method unsets it, so it never reaches the caller.
            if (isset($polled['solution']) && is_array($polled['solution'])) {
                $result['solution'] = $polled['solution'];
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
        if ($method === 'geetest') {
            return ApiParams::prepareSubmitParams($params, 'geetest');
        }
        if ($method === 'altcha') {
            return ApiParams::prepareSubmitParams($params, 'altcha');
        }
        if ($method === 'capy') {
            return ApiParams::prepareSubmitParams($params, 'capy');
        }
        if ($method === 'captchafox') {
            return ApiParams::prepareSubmitParams($params, 'captchafox');
        }
        if ($method === 'friendly_captcha') {
            return ApiParams::prepareSubmitParams($params, 'friendly_captcha');
        }

        return ApiParams::applyProxy(ApiParams::applyParamAliases($params));
    }
}

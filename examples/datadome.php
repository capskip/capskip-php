<?php

/**
 * Pass a DataDome challenge and fetch the page with the cookie.
 *
 * DataDome answers a request it doubts with a block page (status 403, sometimes
 * 401) instead of the site. That page carries a `var dd={...}` object and loads
 * the challenge in an iframe from geo.captcha-delivery.com: a slider under
 * /captcha/, or a few-second device check under /interstitial/. CapSkip passes
 * either and returns the `datadome` cookie.
 *
 * The cookie belongs to one IP address and one User-Agent, so:
 *
 *   * solve through the proxy your requests use (or none, if they leave from
 *     the machine running CapSkip), and
 *   * send the User-Agent CapSkip reports back with every request.
 *
 * When you send no proxy and the CapSkip app has its own DataDome proxy list,
 * it solves through one of those and the result names it as `proxy`
 * (host:port): the cookie then only works through that proxy.
 *
 * Environment:
 *   TARGET_URL         a page behind DataDome (default: https://www.example.com/)
 *   DATADOME_PROXY     optional, e.g. http://login:password@203.0.113.10:8080
 *   CAPSKIP_API_KEY / CAPSKIP_HOST / CAPSKIP_PORT
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use CapSkip\CapSkip;

$solver = new CapSkip([
    'apiKey' => getenv('CAPSKIP_API_KEY') ?: 'capskip',
    'host' => getenv('CAPSKIP_HOST') ?: '127.0.0.1',
    'port' => (int) (getenv('CAPSKIP_PORT') ?: 8080),
]);

$pageUrl = getenv('TARGET_URL') ?: 'https://www.example.com/';
$proxyUrl = getenv('DATADOME_PROXY') ?: null; // scheme://[login:password@]host:port, or unset

/** The challenge iframe address, as the block page's own script builds it. */
function captchaUrlFromBlockPage(string $html, string $pageUrl): ?string
{
    if (preg_match('/<iframe[^>]+src="(https:\/\/[^"]*captcha-delivery\.com\/[^"]+)"/', $html, $m)) {
        return $m[1]; // HTML-escaped &amp; is fine, CapSkip decodes it
    }
    if (!preg_match('/var dd=(\{[^}]*\})/', $html, $m)) {
        return null;
    }
    $dd = json_decode(str_replace("'", '"', $m[1]), true);
    if (!is_array($dd)) {
        return null;
    }
    $path = ($dd['rt'] ?? '') === 'i' ? 'interstitial' : 'captcha';
    $query = ['initialCid' => $dd['cid'], 'hash' => $dd['hsh'], 'cid' => $dd['cookie']];
    if (!empty($dd['t'])) {
        $query['t'] = $dd['t'];
    }
    $query['referer'] = $pageUrl;
    $query['s'] = $dd['s'];
    foreach (['e', 'b'] as $key) {
        if (!empty($dd[$key])) {
            $query[$key] = $dd[$key];
        }
    }
    $query['dm'] = 'cd';

    return 'https://' . ($dd['host'] ?? 'geo.captcha-delivery.com') . "/{$path}/?"
        . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
}

/**
 * GET a page, returning [status, body].
 *
 * @param array<int, string> $headers
 *
 * @return array{0: int, 1: string}
 */
function fetch(string $url, ?string $proxy, array $headers = []): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_HTTPHEADER => $headers,
    ]);
    if ($proxy !== null) {
        curl_setopt($ch, CURLOPT_PROXY, $proxy);
    }
    $body = (string) curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return [$status, $body];
}

try {
    // 1. Request the page. A 403/401 with a dd object is DataDome's block page.
    [$status, $html] = fetch($pageUrl, $proxyUrl);
    $captchaUrl = captchaUrlFromBlockPage($html, $pageUrl);
    if (!in_array($status, [401, 403], true) || $captchaUrl === null) {
        echo "No DataDome block page at {$pageUrl} (HTTP {$status}).", PHP_EOL;
        exit(0);
    }
    echo 'Block page: ', substr($captchaUrl, 0, 90), '...', PHP_EOL;

    // 2. Solve it. 2Captcha's order: captcha_url, pageurl, then userAgent and
    //    proxy in $options. No userAgent: CapSkip uses its own browser's and
    //    reports it. With one, it must be Chrome for Windows with the same
    //    major version as that browser.
    $options = [];
    if ($proxyUrl !== null) {
        $scheme = parse_url($proxyUrl, PHP_URL_SCHEME) ?: 'http';
        $uri = str_contains($proxyUrl, '://') ? explode('://', $proxyUrl, 2)[1] : $proxyUrl;
        $options['proxy'] = ['type' => strtoupper($scheme), 'uri' => $uri];
    }
    $result = $solver->dataDome($captchaUrl, $pageUrl, $options);

    echo 'Captcha ID: ', $result['captchaId'], PHP_EOL;
    echo 'Cookie:     ', substr($result['cookie'], 0, 60), '...', PHP_EOL;
    echo 'User-Agent: ', $result['userAgent'], PHP_EOL;
    if (isset($result['proxy'])) {
        echo 'Proxy:      ', $result['proxy'],
            ' (from the CapSkip app DataDome list; the cookie only works through it)', PHP_EOL;
    }

    // 3. Repeat the request with the cookie, the same User-Agent and the same IP.
    $via = $proxyUrl;
    if (isset($result['proxy']) && $proxyUrl === null) {
        // Named without its login: add yours if that list entry has one.
        $via = 'http://' . $result['proxy'];
    }
    // `cookie` is the datadome=... pair; `code` keeps the Domain, Max-Age and
    // the rest, for a cookie jar.
    [$status] = fetch($pageUrl, $via, [
        'User-Agent: ' . $result['userAgent'],
        'Cookie: ' . $result['cookie'],
    ]);
    echo 'With the cookie: HTTP ', $status, PHP_EOL;
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), PHP_EOL;
    exit(1);
}

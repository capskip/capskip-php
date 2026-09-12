<?php

/**
 * Solve an ALTCHA proof-of-work challenge.
 *
 * ALTCHA is not a recognition captcha -- there is nothing to read. The site
 * issues a challenge and the browser must brute-force a number that satisfies
 * it. CapSkip does that work for you, in milliseconds.
 *
 * You need the challenge, in one of two forms:
 *
 *   * `challenge_url`  - the endpoint that serves it; CapSkip fetches it for you
 *   * `challenge_json` - the challenge document itself, if you already have it
 *
 * To find them, open DevTools -> Network on the target page and look for the
 * request the `<altcha-widget>` makes for its challenge (often something like
 * `/altcha/challenge`). The request URL is your `challenge_url`; its JSON
 * response is your `challenge_json`.
 *
 * Note the widget attribute that names the endpoint changed between versions:
 * v1/v2 use `challengeurl="..."`, while v3+ uses `challenge="..."` for both a
 * URL and inline data. Read the page source rather than assuming.
 *
 * Challenges expire fast -- some sites inside two minutes -- so fetch one
 * immediately before solving and post the token promptly. An expired challenge
 * is rejected with a bare "verification failed" that looks exactly like a wrong
 * answer.
 *
 * This example issues its own challenge the way a site's server would, so it
 * runs as-is with no third-party dependency. Swap in your target's endpoint to
 * use it for real.
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use CapSkip\CapSkip;

$solver = new CapSkip([
    'apiKey' => getenv('CAPSKIP_API_KEY') ?: 'capskip',
    'host' => getenv('CAPSKIP_HOST') ?: '127.0.0.1',
    'port' => (int) (getenv('CAPSKIP_PORT') ?: 8080),
]);

const PAGE_URL = 'https://example.com/signup';

/**
 * Mint an ALTCHA challenge, exactly as a site's own server would.
 *
 * Replace this with a fetch of your target's challenge endpoint -- or skip it
 * entirely and pass `challenge_url` so CapSkip does the fetching.
 *
 * @return array<string, mixed>
 */
function issueChallenge(int $number = 54321): array
{
    $salt = bin2hex(random_bytes(12)) . '?expires=' . (time() + 600);

    return [
        'algorithm' => 'SHA-256',
        'challenge' => hash('sha256', $salt . $number),
        'salt' => $salt,
        'signature' => str_repeat('0', 64),
        'maxnumber' => 100000,
    ];
}

// --- Option A: you already have the challenge document -----------------------
// No network request at all: CapSkip solves it locally.
$result = $solver->altcha(PAGE_URL, ['challenge_json' => issueChallenge()]);

echo 'Captcha ID: ' . $result['captchaId'] . PHP_EOL;
echo 'Number:     ' . $result['number'] . PHP_EOL;
echo 'Token:      ' . substr($result['token'], 0, 60) . '...' . PHP_EOL;

// --- Option B: let CapSkip fetch the challenge --------------------------------
// Point it at the endpoint the widget calls. Add `proxy` if the endpoint should
// be fetched from a particular IP -- the proxy is used only for that fetch,
// never for the solve itself.
//
//   $result = $solver->altcha(PAGE_URL, [
//       'challenge_url' => 'https://example.com/captcha/api/altcha/challenge',
//       'proxy' => ['type' => 'HTTP', 'uri' => 'login:password@1.2.3.4:8080'],
//   ]);

// Post the token back in the form field the widget uses, named `altcha`:
//
//   $post = http_build_query([
//       'email' => 'someone@example.com',
//       'altcha' => $result['token'],
//   ]);
//
// Do not re-encode, trim or re-order it. The token is base64 of a JSON document
// whose fields are covered by the server's HMAC signature, so any modification
// invalidates it.

// `code` holds the same string as `token`, which is what you forward if you are
// porting code written against another solver's API.
assert($result['code'] === $result['token']);

<?php

/**
 * Solve a Capy Puzzle captcha.
 *
 * Capy is a slide puzzle: a piece has to be dragged into the hole cut out of a
 * photograph. CapSkip locates the hole and produces the drag path, which takes
 * one HTTP fetch and about a tenth of a second of pixel math -- no browser.
 *
 * You need two values, both read off the target page:
 *
 *   * captchakey - the site's public Capy key, prefixed `PUZZLE_`. Look for
 *     `capy_captchakey` in the page source, or read it out of the widget script
 *     URL: <script src="https://jp.api.capy.me/puzzle/get_js/?k=PUZZLE_XXXX">
 *   * pageurl    - the full URL of the page the captcha is on
 *
 * Optionally `api_server`, the root of the Capy API the key lives behind, taken
 * from that same script URL. CapSkip defaults to https://jp.api.capy.me.
 * Note that api.capy.me no longer resolves, although several solver services
 * still document it -- if a site's widget points elsewhere, pass that.
 *
 * Unlike reCAPTCHA or Turnstile, the answer is NOT a single token. It is three
 * values that together go into the target form, and all three must be submitted.
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use CapSkip\CapSkip;

$solver = new CapSkip([
    'apiKey' => getenv('CAPSKIP_API_KEY') ?: 'capskip',
    'host' => getenv('CAPSKIP_HOST') ?: '127.0.0.1',
    'port' => (int) (getenv('CAPSKIP_PORT') ?: 8080),
]);

const CAPTCHA_KEY = 'PUZZLE_Abc1dEFghIJKLM2no34P56q7rStu8v';
const PAGE_URL = 'https://example.com/login';

try {
    $result = $solver->capy(CAPTCHA_KEY, PAGE_URL);

    echo 'Captcha ID:    ', $result['captchaId'], PHP_EOL;
    echo 'Captcha key:   ', $result['captchakey'], PHP_EOL;
    echo 'Challenge key: ', $result['challengekey'], PHP_EOL;
    echo 'Answer:        ', substr($result['answer'], 0, 48), '...', PHP_EOL;

    // --- With a non-default API server -----------------------------------
    //
    //   $result = $solver->capy(CAPTCHA_KEY, PAGE_URL, [
    //       'api_server' => 'https://jp.api.capy.me/',
    //   ]);
    //
    // --- With a per-request proxy ----------------------------------------
    // The proxy is used for the puzzle-image fetch, which is the only request a
    // Capy solve makes. Worth configuring once you solve at any volume: a steady
    // stream of puzzle draws from one address is the pattern rate limiting
    // exists to catch.
    //
    //   $result = $solver->capy(CAPTCHA_KEY, PAGE_URL, [
    //       'proxy' => ['type' => 'HTTP', 'uri' => 'login:password@1.2.3.4:8080'],
    //   ]);

    // Post all three values back in the fields the Capy widget would have filled
    // in. Submit them verbatim -- `answer` is the drag path the widget would
    // have recorded, and the site's backend verifies it against the challenge it
    // issued, so trimming or re-encoding it invalidates the solve.
    //
    //   $post = [
    //       'email' => 'someone@example.com',
    //       'capy_captchakey' => $result['captchakey'],
    //       'capy_challengekey' => $result['challengekey'],
    //       'capy_answer' => $result['answer'],
    //   ];
    echo 'Form fields:   capy_captchakey, capy_challengekey, capy_answer', PHP_EOL;

    // The challenge key is single-use and short-lived: CapSkip generates it at
    // solve time and the puzzle is bound to it. Submit promptly rather than
    // caching these values or reusing a challengekey for a second submission.
    //
    // One more thing worth knowing if you ever build against Capy yourself: it
    // grades the wall-clock gap between issuing the puzzle and verifying the
    // answer, and refuses anything superhuman with "CAPTCHA verification failed"
    // -- the same message a wrong answer gets. CapSkip therefore holds every
    // result until two seconds have elapsed, so a solve takes about that long
    // rather than a fifth of a second. Nothing to configure; just do not be
    // surprised by the latency.

    // `respKey` comes back empty for a puzzle solve. It exists for shape
    // compatibility with 2Captcha's documented response and carries nothing.
    echo 'respKey empty: ', ($result['respKey'] === '' ? 'yes' : 'no'), PHP_EOL;
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), PHP_EOL;
    exit(1);
}

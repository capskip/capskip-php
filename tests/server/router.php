<?php

declare(strict_types=1);

/**
 * Router for a local mock CapSkip server, driven by PHP's built-in web server.
 * It exercises the full submit/poll round trip over real HTTP, mirroring
 * tests/conftest.py from the Python SDK.
 *
 * The built-in server runs each request in a fresh process context, so the id
 * counter, id→type map, and per-id poll counts are persisted to a small JSON
 * state file (its directory comes from the CAPSKIP_STATE_DIR env var).
 */

const MOCK_CODE = 'SOLVED_TOKEN_abc123';
const MOCK_USER_AGENT = 'CapSkipUA/1.0';

// ALTCHA answers are base64 of the challenge document with the winning counter
// added, so the mock has to return a real one for the token/number parsing to
// mean anything.
const MOCK_ALTCHA_NUMBER = 9661;
const MOCK_ALTCHA_TOKEN = 'eyJhbGdvcml0aG0iOiJTSEEtMjU2IiwiY2hhbGxlbmdlIjoiM2RkMjgyNTNiZTZjYzBjNTRkOTVmN2Y5OGM1MTdlNjgiLCJudW1iZXIiOjk2NjEsInNhbHQiOiI0NmQ1YjFjODg3MWU1MTUyZDkwMmVlM2Y/ZXhwaXJlcz0xODkzNDU2MDAwIiwic2lnbmF0dXJlIjoiNGIxY2YwZTBiZTBmNGU1MjQ3ZTUwYjBmOWE0NDk4MzAiLCJ0b29rIjoxNi41OH0=';

// Capy answers are not a token: three values that together go into the target
// form. `answer` is the drag path the widget would have recorded, so the mock
// carries a realistic one -- the expansion is only meaningful against a real
// shape.
const MOCK_CAPY_SOLUTION_JSON = '{"captchakey":"PUZZLE_Abc1dEFghIJKLM2no34P56q7rStu8v","challengekey":"BalY2gJaI8uA2SGVOZhqBQ3V0CYSNNGP","answer":"0xax8ex0xax84x0xkx7qx0x18x76x0x1ix6sx0x26x68x0x2gx5kx0x34x50x","respKey":""}';

const MOCK_CAPTCHAFOX_TOKEN = '177f50c25b845601e5c779cdb51b040d523e8ab69efb4d5b343e28df07d05076';
// The UA the browser actually minted the token under -- deliberately not one a
// caller could have sent, so a test can tell the two apart.
const MOCK_CAPTCHAFOX_USER_AGENT = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/146.0.0.0 Safari/537.36';

// A v1 token: four dot-separated parts. A v2 one is a single opaque string of
// roughly six kilobytes, which shape-wise changes nothing the SDK does with it.
const MOCK_FRIENDLY_CAPTCHA_TOKEN = 'c62c4da36bbaf7f253873035832709ef.aqwpWwdbzRWKY/UQAQwwpgAAAAAAAAAAM7hBvJOzqjc=.AAAAAArcCQABAAAAxv8QAAIAAACKYRgA.AgAB';

// An AWS WAF answer is two values that only work as a pair, so like Capy's it is
// an object: json=1 puts it straight into `request`, and plain text sends it as
// one line of JSON after OK|. The shapes follow what AWS really issues -- a
// dotted voucher, and a token of three colon-separated parts.
const MOCK_AMAZON_WAF_SOLUTION_JSON = '{"captcha_voucher":"eyJ2b3VjaGVyIjoiRVhBTVBMRSJ9.9QwZcEXAMPLEvoucherJk4LmN0pQ","existing_token":"7d4c2a91-EXAMPLE-4f0b-9e6d-3a1b5c8e2f70:EQoAeXAMPLEtokenAAAA:Wm9wc2E="}';

// DataDome answers with a cookie, not a token: the Set-Cookie value exactly as
// DataDome set it, attributes included. Next to it CapSkip names the User-Agent
// the cookie was earned with (the caller's, or its own browser's when the task
// sent none) and, only when the task sent no proxy and the app's DataDome proxy
// list supplied one, that proxy as host:port.
const MOCK_DATADOME_COOKIE = 'datadome=FAPPpli4jkT1MZGtgF8ez7mjSz8MrJ9fGRNE51Gj79cq9fXp5xn~F1P5APWjqvJojf4U9dq0R81Xaj2hpYtjle95mkg; Max-Age=31536000; Domain=.example.com; Path=/; Secure; SameSite=Lax';
const MOCK_DATADOME_BROWSER_USER_AGENT = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/156.0.0.0 Safari/537.36';
const MOCK_DATADOME_LIST_PROXY = '203.0.113.10:8080';

// hCaptcha, as CapSkip's res.php answers it with json=1: the token as
// `request`, the widget's respKey, and the UA of the browser that earned it --
// never the caller's own, so a test can tell the two apart.
const MOCK_HCAPTCHA_TOKEN = 'P1_eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.hadwYXNza2V5xQZ2f3Kq8nV2';
const MOCK_HCAPTCHA_RESP_KEY = 'E0_eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJpZCI6IjA09wB1';
const MOCK_HCAPTCHA_USER_AGENT = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/156.0.0.0 Safari/537.36';

// MTCaptcha, as CapSkip's res.php answers it: the verified token MTCaptcha
// issued, v1( then comma-separated parts (the sitekey among them) and a
// base64url payload, closed by **). Nothing else rides with it.
const MOCK_MTCAPTCHA_TOKEN = 'v1(0f3c9e2a,7b1d4c88,MTPublic-KzqLY1cKH,5e0c2a9f61d84b37a0c1e9b2d6f4a873,Qm9ndXNUb2tlbkZvclRlc3RzT25seV9fTm90QVJlYWxNVENhcHRjaGFUb2tlbi1BYkNkRWZHaA**)';

// A minimal valid 1x1 PNG. The SDK never inspects the bytes, so exact pixels do
// not matter — the mock just needs to return something for /image.png.
const MOCK_PNG_BASE64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==';

$stateDir = getenv('CAPSKIP_STATE_DIR') ?: sys_get_temp_dir();
$stateFile = $stateDir . DIRECTORY_SEPARATOR . 'capskip_state.json';

$loadState = static function (string $file): array {
    if (!file_exists($file)) {
        return ['counter' => 0, 'idType' => [], 'pollCount' => []];
    }
    $raw = @file_get_contents($file);
    $data = $raw === false ? null : json_decode($raw, true);

    return is_array($data) ? $data : ['counter' => 0, 'idType' => [], 'pollCount' => []];
};

$saveState = static function (string $file, array $state): void {
    file_put_contents($file, json_encode($state), LOCK_EX);
};

$sendText = static function (string $text, string $ctype = 'text/plain'): void {
    header('Content-Type: ' . $ctype);
    echo $text;
};

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);

if ($method === 'GET' && $path === '/image.png') {
    header('Content-Type: image/png');
    echo base64_decode(MOCK_PNG_BASE64);
    exit;
}

// The fields the most recent in.php submit carried, as the server decoded them
// (the API key left out), so a test can check a value crossed the wire intact.
if ($method === 'GET' && $path === '/last_submit') {
    $sendText((string) json_encode($loadState($stateFile)['lastSubmit'] ?? new stdClass()), 'application/json');
    exit;
}

if ($method === 'GET' && $path === '/res.php') {
    $cid = (string) ($_GET['id'] ?? '');
    $wantJson = (string) ($_GET['json'] ?? '') === '1';

    $state = $loadState($stateFile);
    $state['pollCount'][$cid] = ($state['pollCount'][$cid] ?? 0) + 1;
    $count = $state['pollCount'][$cid];
    $saveState($stateFile, $state);

    // CapSkip returns an empty 200 body when no result is available yet (briefly
    // right after submit, for an unknown id, or once a solved token has already
    // been read). It must be treated as "not ready".
    if (strpos($cid, 'empty') === 0 && $count < 3) {
        $sendText('');
        exit;
    }

    $notReady = strpos($cid, 'never') === 0
        || (strpos($cid, 'slow') === 0 && $count < 2);

    if ($notReady) {
        $sendText(
            $wantJson ? '{"status":0,"request":"CAPCHA_NOT_READY"}' : 'CAPCHA_NOT_READY',
            $wantJson ? 'application/json' : 'text/plain'
        );
    } elseif (($state['idType'][$cid] ?? '') === 'altcha') {
        // CapSkip emits a superset: the legacy status/request pair plus the
        // createTask-shaped solution object.
        if ($wantJson) {
            $sendText((string) json_encode([
                'status' => 1,
                'request' => MOCK_ALTCHA_TOKEN,
                'solution' => ['token' => MOCK_ALTCHA_TOKEN, 'number' => MOCK_ALTCHA_NUMBER],
            ]), 'application/json');
        } else {
            $sendText('OK|' . MOCK_ALTCHA_TOKEN);
        }
    } elseif (($state['idType'][$cid] ?? '') === 'capy') {
        // The one method whose answer is an object rather than a string: json=1
        // puts it straight into `request`, and plain text sends it as a single
        // line of JSON after OK|.
        $capy = json_decode(MOCK_CAPY_SOLUTION_JSON, true);
        if ($wantJson) {
            $sendText((string) json_encode([
                'status' => 1,
                'request' => $capy,
                'solution' => $capy,
            ]), 'application/json');
        } else {
            $sendText('OK|' . MOCK_CAPY_SOLUTION_JSON);
        }
    } elseif (($state['idType'][$cid] ?? '') === 'amazon_waf') {
        // What CapSkip sends: the bare status/request pair, with the two-value
        // object as `request` -- no createTask `solution`.
        if ($wantJson) {
            $sendText((string) json_encode([
                'status' => 1,
                'request' => json_decode(MOCK_AMAZON_WAF_SOLUTION_JSON, true),
            ]), 'application/json');
        } else {
            $sendText('OK|' . MOCK_AMAZON_WAF_SOLUTION_JSON);
        }
    } elseif (($state['idType'][$cid] ?? '') === 'datadome') {
        // As CapSkip answers: the cookie as `request`, the User-Agent it was
        // earned with, and `proxy` only when the app's own list supplied one.
        $sent = $state['idFields'][$cid] ?? [];
        $userAgent = ($sent['userAgent'] ?? '') ?: (($sent['useragent'] ?? '') ?: MOCK_DATADOME_BROWSER_USER_AGENT);
        if ($wantJson) {
            $reply = [
                'status' => 1,
                'request' => MOCK_DATADOME_COOKIE,
                'userAgent' => $userAgent,
            ];
            if (($sent['proxy'] ?? '') === '') {
                $reply['proxy'] = MOCK_DATADOME_LIST_PROXY;
            }
            $sendText((string) json_encode($reply), 'application/json');
        } else {
            $sendText('OK|' . MOCK_DATADOME_COOKIE);
        }
    } elseif (($state['idType'][$cid] ?? '') === 'hcaptcha') {
        // Plain text carries the UA in X-HCaptcha-User-Agent instead.
        if ($wantJson) {
            $sendText((string) json_encode([
                'status' => 1,
                'request' => MOCK_HCAPTCHA_TOKEN,
                'respKey' => MOCK_HCAPTCHA_RESP_KEY,
                'useragent' => MOCK_HCAPTCHA_USER_AGENT,
            ]), 'application/json');
        } else {
            header('X-HCaptcha-User-Agent: ' . MOCK_HCAPTCHA_USER_AGENT);
            $sendText('OK|' . MOCK_HCAPTCHA_TOKEN);
        }
    } elseif (in_array($state['idType'][$cid] ?? '', ['mt_captcha', 'mtcaptcha'], true)) {
        // The bare status/request pair: the token and nothing else.
        if ($wantJson) {
            $sendText((string) json_encode([
                'status' => 1,
                'request' => MOCK_MTCAPTCHA_TOKEN,
            ]), 'application/json');
        } else {
            $sendText('OK|' . MOCK_MTCAPTCHA_TOKEN);
        }
    } elseif (($state['idType'][$cid] ?? '') === 'captchafox') {
        // The UA is the browser's own, and CapSkip reports it at the top level
        // and inside solution both.
        if ($wantJson) {
            $sendText((string) json_encode([
                'status' => 1,
                'request' => MOCK_CAPTCHAFOX_TOKEN,
                'userAgent' => MOCK_CAPTCHAFOX_USER_AGENT,
                'solution' => [
                    'token' => MOCK_CAPTCHAFOX_TOKEN,
                    'userAgent' => MOCK_CAPTCHAFOX_USER_AGENT,
                ],
            ]), 'application/json');
        } else {
            $sendText('OK|' . MOCK_CAPTCHAFOX_TOKEN);
        }
    } elseif (($state['idType'][$cid] ?? '') === 'friendly_captcha') {
        if ($wantJson) {
            $sendText((string) json_encode([
                'status' => 1,
                'request' => MOCK_FRIENDLY_CAPTCHA_TOKEN,
                'solution' => ['token' => MOCK_FRIENDLY_CAPTCHA_TOKEN],
            ]), 'application/json');
        } else {
            $sendText('OK|' . MOCK_FRIENDLY_CAPTCHA_TOKEN);
        }
    } elseif ($wantJson && ($state['idType'][$cid] ?? '') === 'turnstile') {
        $sendText('{"status":1,"request":"' . MOCK_CODE . '","useragent":"' . MOCK_USER_AGENT . '"}', 'application/json');
    } elseif ($wantJson) {
        $sendText('{"status":1,"request":"' . MOCK_CODE . '"}', 'application/json');
    } else {
        $sendText('OK|' . MOCK_CODE);
    }
    exit;
}

if ($method === 'POST' && $path === '/in.php') {
    $key = $_POST['key'] ?? 'capskip';
    if ($key === 'badkey') {
        $sendText('ERROR_WRONG_USER_KEY');
        exit;
    }

    $submitMethod = (string) ($_POST['method'] ?? '');

    // CapSkip reads hCaptcha's invisible as "1" or "true" only; a value
    // stringified any other way (a PHP false as "0", say, or "" for null)
    // would silently mean "visible". The mock refuses it so the integration
    // test catches the slip.
    if ($submitMethod === 'hcaptcha' && array_key_exists('invisible', $_POST)
        && !in_array((string) $_POST['invisible'], ['1', 'true'], true)
    ) {
        $sendText('ERROR_BAD_PARAMETERS');
        exit;
    }

    // CapSkip's own sitekey test for MTCaptcha. The SDK refuses a bad one
    // first, so reaching here with one means local validation slipped.
    if (in_array($submitMethod, ['mt_captcha', 'mtcaptcha'], true)
        && preg_match('~^MTPublic-[A-Za-z0-9_-]{1,55}$~D', (string) ($_POST['sitekey'] ?? '')) !== 1
    ) {
        $sendText('ERROR_BAD_PARAMETERS');
        exit;
    }
    $pageurl = (string) ($_POST['pageurl'] ?? '');

    $state = $loadState($stateFile);
    $state['counter'] += 1;
    $n = $state['counter'];

    if (strpos($pageurl, 'never') !== false) {
        $cid = 'never' . $n;
    } elseif (strpos($pageurl, 'slow') !== false) {
        $cid = 'slow' . $n;
    } elseif (strpos($pageurl, 'empty') !== false) {
        $cid = 'empty' . $n;
    } else {
        $cid = (string) $n;
    }

    $state['idType'][$cid] = $submitMethod;
    $lastSubmit = $_POST;
    unset($lastSubmit['key']);
    $state['lastSubmit'] = $lastSubmit;
    // Remembered per id too: a DataDome reply depends on what the submit sent.
    $state['idFields'][$cid] = $lastSubmit;
    $saveState($stateFile, $state);

    // in.php returns JSON when the submit carried json=1, mirroring real CapSkip.
    if ((string) ($_POST['json'] ?? '') === '1') {
        $sendText('{"status":1,"request":"' . $cid . '"}', 'application/json');
    } else {
        $sendText('OK|' . $cid);
    }
    exit;
}

$sendText('ERROR_NOT_FOUND');

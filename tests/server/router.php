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

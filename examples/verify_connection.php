#!/usr/bin/env php
<?php

declare(strict_types=1);

// Verify CapSkip is running and reachable.

require __DIR__ . '/../vendor/autoload.php';

use CapSkip\ApiClient;
use CapSkip\CapSkip;
use CapSkip\Exceptions\ApiException;
use CapSkip\Exceptions\NetworkException;

/**
 * @param array<int, string> $argv
 *
 * @return array{host: string, port: int, apiKey: string}
 */
function parseArgs(array $argv): array
{
    $args = ['host' => '127.0.0.1', 'port' => 8080, 'apiKey' => 'capskip'];
    for ($i = 1; $i < count($argv); $i++) {
        switch ($argv[$i]) {
            case '--host':
                $args['host'] = $argv[++$i] ?? $args['host'];
                break;
            case '--port':
                $args['port'] = (int) ($argv[++$i] ?? $args['port']);
                break;
            case '--api-key':
                $args['apiKey'] = $argv[++$i] ?? $args['apiKey'];
                break;
        }
    }

    return $args;
}

$args = parseArgs($argv);
$client = new ApiClient(['host' => $args['host'], 'port' => $args['port']]);

echo 'CapSkip SDK : ' . CapSkip::VERSION . PHP_EOL;
echo 'Target      : ' . $client->baseUrl() . PHP_EOL;

try {
    $client->res(['key' => $args['apiKey'], 'action' => 'get', 'id' => '0']);
    echo 'Status      : OK — CapSkip is reachable' . PHP_EOL;
} catch (ApiException $e) {
    echo 'Status      : OK — CapSkip is reachable' . PHP_EOL;
    echo 'Response    : ' . $e->getMessage() . PHP_EOL;
} catch (NetworkException $e) {
    echo 'Status      : FAILED — ' . $e->getMessage() . PHP_EOL;
    exit(1);
}

echo 'Try: php examples/recaptcha.php' . PHP_EOL;

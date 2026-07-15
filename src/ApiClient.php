<?php

declare(strict_types=1);

namespace CapSkip;

use CapSkip\Exceptions\ApiException;
use CapSkip\Exceptions\NetworkException;

/** Low-level HTTP client for the CapSkip in.php / res.php endpoints. */
class ApiClient
{
    public string $host;
    public int $port;

    /**
     * @param array{host?: string, port?: int} $options
     */
    public function __construct(array $options = [])
    {
        $this->host = $options['host'] ?? '127.0.0.1';
        $this->port = (int) ($options['port'] ?? 8080);
    }

    public function baseUrl(): string
    {
        return "http://{$this->host}:{$this->port}";
    }

    /**
     * Submit a captcha to `in.php`.
     *
     * A `files` map (`['field' => path]`) or a single `file` path triggers a
     * multipart upload; otherwise the remaining fields are sent url-encoded.
     *
     * @param array<string, mixed> $options
     *
     * @return string The raw `in.php` response text.
     */
    public function in_(array $options = []): string
    {
        $files = $options['files'] ?? [];
        unset($options['files']);
        $fields = $options;
        $url = $this->baseUrl() . '/in.php';

        // File reads happen before the request so a missing file surfaces as a
        // filesystem error rather than being masked as a NetworkException.
        $parts = [];
        if (!empty($files)) {
            foreach ($files as $name => $path) {
                $parts[] = [
                    'name' => (string) $name,
                    'filename' => basename((string) $path),
                    'content' => self::readFile((string) $path),
                ];
            }
        } elseif (array_key_exists('file', $fields)) {
            $path = (string) $fields['file'];
            unset($fields['file']);
            $parts[] = [
                'name' => 'file',
                'filename' => basename($path),
                'content' => self::readFile($path),
            ];
        }

        try {
            $resp = !empty($parts)
                ? Http::postMultipart($url, $fields, $parts)
                : Http::postForm($url, $fields);
        } catch (\RuntimeException $e) {
            throw new NetworkException($e->getMessage(), 0, $e);
        }

        if ($resp['status'] !== 200) {
            throw new NetworkException("bad response: {$resp['status']}");
        }

        $text = $resp['body'];
        if (str_contains($text, 'ERROR')) {
            throw new ApiException($text);
        }

        return $text;
    }

    /**
     * Poll a result from `res.php`.
     *
     * @param array<string, mixed> $params Query parameters (`key`, `action`, `id`, `json`).
     *
     * @return string The raw `res.php` response text.
     */
    public function res(array $params = []): string
    {
        try {
            $resp = Http::get($this->baseUrl() . '/res.php', $params);
        } catch (\RuntimeException $e) {
            throw new NetworkException($e->getMessage(), 0, $e);
        }

        if ($resp['status'] !== 200) {
            throw new NetworkException("bad response: {$resp['status']}");
        }

        $text = $resp['body'];
        if (str_contains($text, 'ERROR')) {
            throw new ApiException($text);
        }

        return $text;
    }

    private static function readFile(string $path): string
    {
        $content = @file_get_contents($path);
        if ($content === false) {
            throw new \RuntimeException("File not found: {$path}");
        }

        return $content;
    }
}

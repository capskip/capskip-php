<?php

declare(strict_types=1);

namespace CapSkip;

/**
 * Minimal HTTP layer built on PHP's cURL extension. CapSkip only ever talks to a
 * local endpoint (in.php / res.php) plus the occasional image download, so a small
 * request helper is all the SDK needs. Transport failures raise a
 * {@see \RuntimeException}, which the callers wrap into a NetworkException.
 */
final class Http
{
    /** Encode an associative array as an application/x-www-form-urlencoded string. */
    public static function encodeParams(array $params): string
    {
        $pairs = [];
        foreach ($params as $key => $value) {
            $pairs[] = rawurlencode((string) $key) . '=' . rawurlencode(self::stringifyValue($value));
        }

        return implode('&', $pairs);
    }

    /** Build a `?a=b&c=d` query string (empty string when there are no params). */
    public static function buildQuery(array $params): string
    {
        $query = self::encodeParams($params);

        return $query === '' ? '' : '?' . $query;
    }

    /**
     * Encode form fields plus file parts as a multipart/form-data body.
     *
     * @param array<string, mixed>                                     $fields
     * @param array<int, array{name: string, filename: string, content: string, contentType?: string}> $files
     *
     * @return array{body: string, contentType: string}
     */
    public static function encodeMultipart(array $fields, array $files): array
    {
        $boundary = '----CapSkipFormBoundary' . bin2hex(random_bytes(16));
        $body = '';

        foreach ($fields as $name => $value) {
            $body .= "--{$boundary}\r\n";
            $body .= "Content-Disposition: form-data; name=\"{$name}\"\r\n\r\n";
            $body .= self::stringifyValue($value) . "\r\n";
        }

        foreach ($files as $file) {
            $contentType = $file['contentType'] ?? 'application/octet-stream';
            $body .= "--{$boundary}\r\n";
            $body .= "Content-Disposition: form-data; name=\"{$file['name']}\"; filename=\"{$file['filename']}\"\r\n";
            $body .= "Content-Type: {$contentType}\r\n\r\n";
            $body .= $file['content'] . "\r\n";
        }

        $body .= "--{$boundary}--\r\n";

        return [
            'body' => $body,
            'contentType' => "multipart/form-data; boundary={$boundary}",
        ];
    }

    /**
     * Perform a single HTTP request, following redirects for safe methods.
     *
     * @param array{headers?: array<int, string>, body?: string|null} $options
     *
     * @return array{status: int, body: string}
     */
    public static function request(string $method, string $url, array $options = []): array
    {
        $headers = $options['headers'] ?? [];
        $body = $options['body'] ?? null;

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_MAXREDIRS, 5);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 30);
        curl_setopt($ch, CURLOPT_TIMEOUT, 120);
        if (!empty($headers)) {
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        }
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }

        $responseBody = curl_exec($ch);
        if ($responseBody === false) {
            $error = curl_error($ch);
            curl_close($ch);

            throw new \RuntimeException($error !== '' ? $error : 'HTTP request failed');
        }

        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return ['status' => $status, 'body' => (string) $responseBody];
    }

    /**
     * POST an application/x-www-form-urlencoded body.
     *
     * @return array{status: int, body: string}
     */
    public static function postForm(string $url, array $fields): array
    {
        return self::request('POST', $url, [
            'headers' => ['Content-Type: application/x-www-form-urlencoded'],
            'body' => self::encodeParams($fields),
        ]);
    }

    /**
     * POST a multipart/form-data body carrying one or more file parts.
     *
     * @param array<int, array{name: string, filename: string, content: string, contentType?: string}> $files
     *
     * @return array{status: int, body: string}
     */
    public static function postMultipart(string $url, array $fields, array $files): array
    {
        $encoded = self::encodeMultipart($fields, $files);

        return self::request('POST', $url, [
            'headers' => ['Content-Type: ' . $encoded['contentType']],
            'body' => $encoded['body'],
        ]);
    }

    /**
     * GET with an associative array of query parameters.
     *
     * @return array{status: int, body: string}
     */
    public static function get(string $url, array $params = []): array
    {
        return self::request('GET', $url . self::buildQuery($params));
    }

    /** @param mixed $value */
    private static function stringifyValue($value): string
    {
        if ($value === null) {
            return '';
        }
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        return (string) $value;
    }
}

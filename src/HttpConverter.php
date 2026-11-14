<?php

declare(strict_types=1);

namespace JDecool\ScalewayRuntime;

use Riverline\MultiPartParser\StreamedPart;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Converts an Event into a Symfony Request, and a Symfony Response into a Function Response.
 *
 * @phpstan-type FunctionResponse array{statusCode: int, headers?: array<string, string|list<string>>, body: string, isBase64Encoded?: bool}
 */
final class HttpConverter
{
    /**
     * The client address of Events without X-Real-Ip, which only CRON Invocations lack: they come from the platform itself.
     */
    public const string PROXY_ADDRESS = '127.0.0.1';

    /**
     * The client, scheme and host come only from what the platform sets itself, as clients can forge the other
     * forwarding headers: no proxy is trusted (see docs/adr/0007-trust-only-headers-the-platform-overwrites.md).
     *
     * @param array<mixed> $event
     * @param string       $host  the public host of the function, as no Host header reaches it
     */
    public static function toRequest(array $event, string $host = 'localhost'): Request
    {
        $method = strtoupper(self::string($event['httpMethod'] ?? $event['method'] ?? null) ?? 'GET');
        $path = '/'.ltrim(self::string($event['path'] ?? null) ?? '', '/');
        // An explicit separator, as arg_separator.output may be "&amp;".
        $queryString = http_build_query(self::stringMap($event['queryStringParameters'] ?? null), '', '&');
        parse_str($queryString, $query);

        $body = self::string($event['body'] ?? null) ?? '';
        if (true === ($event['isBase64Encoded'] ?? false)) {
            $body = base64_decode($body, true);
            if (false === $body) {
                throw new \UnexpectedValueException('The Event body is flagged as base64 but is not valid base64.');
            }
        }

        $server = [
            'REQUEST_METHOD' => $method,
            'REQUEST_URI' => '' === $queryString ? $path : $path.'?'.$queryString,
            'QUERY_STRING' => $queryString,
            'SERVER_PROTOCOL' => 'HTTP/1.1',
            'REQUEST_TIME' => time(),
            'REQUEST_TIME_FLOAT' => microtime(true),
        ];
        $cookies = [];
        $contentType = '';
        $contentTypeHeader = '';
        foreach (self::stringMap($event['headers'] ?? null) as $name => $value) {
            $key = strtoupper(str_replace('-', '_', $name));
            // PHP exposes these two without the HTTP_ prefix, and HeaderBag expects them that way.
            $server[\in_array($key, ['CONTENT_TYPE', 'CONTENT_LENGTH'], true) ? $key : 'HTTP_'.$key] = $value;
            if ('COOKIE' === $key) {
                $cookies = self::parseCookies($value);
            } elseif ('CONTENT_TYPE' === $key) {
                $contentTypeHeader = $value;
                $contentType = strtolower($value);
            }
        }
        // The platform overwrites X-Real-Ip and X-Forwarded-Proto on every HTTP call, whatever the client sends.
        $server['REMOTE_ADDR'] = filter_var($server['HTTP_X_REAL_IP'] ?? '', \FILTER_VALIDATE_IP) ?: self::PROXY_ADDRESS;
        $proto = $server['HTTP_X_FORWARDED_PROTO'] ?? null;
        $secure = \is_string($proto) && 'https' === strtolower($proto);
        $server['HTTPS'] = $secure ? 'on' : 'off';
        $server['SERVER_PORT'] = $secure ? 443 : 80;
        // The platform drops the client's Host, and an Event carrying one cannot come from it.
        $server['HTTP_HOST'] = '' === $host ? 'localhost' : $host;

        // PHP only parses form bodies into $_POST for POST; Symfony's createFromGlobals() covers the other body methods.
        $post = $files = [];
        if (\in_array($method, ['POST', 'PUT', 'DELETE', 'PATCH', 'QUERY'], true)
            && str_starts_with($contentType, 'application/x-www-form-urlencoded')
        ) {
            parse_str($body, $post);
        } elseif ('POST' === $method && str_starts_with($contentType, 'multipart/form-data')) {
            [$post, $files] = self::parseMultipart($contentTypeHeader, $body);
        }

        return new Request($query, $post, [], $cookies, $files, $server, $body);
    }

    /**
     * Fills fields and files like PHP does for a multipart POST: files land in temp files,
     * which the caller must delete once the Invocation is over, as PHP does at the end of a request.
     *
     * @return array{array<mixed>, array<mixed>}
     */
    private static function parseMultipart(string $contentType, string $body): array
    {
        $stream = fopen('php://temp', 'r+');
        \assert(false !== $stream);
        fwrite($stream, 'Content-Type: '.$contentType."\r\n\r\n".$body);
        try {
            $parts = new StreamedPart($stream)->getParts();
        } catch (\InvalidArgumentException|\LogicException) {
            // PHP leaves $_POST and $_FILES empty for a malformed body rather than failing the request.
            return [[], []];
        } finally {
            fclose($stream);
        }

        $fields = $fileFields = $uploads = [];
        foreach ($parts as $part) {
            $name = $part->getName();
            if (!\is_string($name) || '' === $name) {
                continue;
            }
            // Field names go through parse_str() so "a[b][]" nests exactly as PHP nests it.
            if (!$part->isFile()) {
                $fields[] = rawurlencode($name).'='.rawurlencode((string) $part->getBody());
                continue;
            }

            $fileFields[] = rawurlencode($name).'='.\count($uploads);
            $fileName = (string) $part->getFileName();
            if ('' === $fileName) {
                // A file input left empty: the $_FILES entry PHP builds, which Symfony's FileBag turns into null.
                $uploads[] = ['name' => '', 'type' => '', 'tmp_name' => '', 'error' => \UPLOAD_ERR_NO_FILE, 'size' => 0];
                continue;
            }
            $path = tempnam(sys_get_temp_dir(), 'php');
            \assert(false !== $path);
            file_put_contents($path, (string) $part->getBody());
            // Test mode, as the file was not uploaded through the SAPI and is_uploaded_file() would reject it.
            $uploads[] = new UploadedFile($path, $fileName, (string) $part->getMimeType(), \UPLOAD_ERR_OK, true);
        }

        parse_str(implode('&', $fields), $post);
        parse_str(implode('&', $fileFields), $files);
        array_walk_recursive($files, static function (mixed &$index) use ($uploads): void {
            $index = \is_string($index) ? $uploads[(int) $index] : null;
        });

        return [$post, $files];
    }

    /**
     * @return array{statusCode: int, headers: array<string, string|list<string>>, body: string, isBase64Encoded?: true}
     */
    public static function toFunctionResponse(Response $response): array
    {
        $body = '';
        $level = ob_get_level();
        // A callback handler keeps the chunks even when a streamed callback calls ob_flush().
        ob_start(static function (string $chunk) use (&$body): string {
            $body .= $chunk;

            return '';
        });
        try {
            $response->sendContent();
        } finally {
            // Buffers the Application left open would swallow the output of later Invocations,
            // and ours may already be gone if it closed every buffer.
            while (ob_get_level() > $level) {
                ob_end_flush();
            }
        }

        $values = $response->headers->allPreserveCaseWithoutCookies();
        $values['Set-Cookie'] = array_map(strval(...), $response->headers->getCookies());
        $headers = [];
        foreach ($values as $name => $list) {
            $list = array_values(array_filter(\is_array($list) ? $list : [], \is_string(...)));
            if ([] !== $list) {
                $headers[(string) $name] = 1 === \count($list) ? $list[0] : $list;
            }
        }

        $functionResponse = [
            'statusCode' => $response->getStatusCode(),
            'headers' => $headers,
            'body' => $body,
        ];
        if (1 !== preg_match('//u', $body)) {
            $functionResponse['body'] = base64_encode($body);
            $functionResponse['isBase64Encoded'] = true;
        }

        return $functionResponse;
    }

    /**
     * Mirrors how PHP fills $_COOKIE: the first occurrence of a name wins and values are URL-decoded.
     *
     * @return array<string, string>
     */
    private static function parseCookies(string $header): array
    {
        $cookies = [];
        foreach (explode(';', $header) as $pair) {
            $parts = explode('=', $pair, 2);
            $name = trim($parts[0]);
            if ('' !== $name && isset($parts[1])) {
                $cookies[$name] ??= urldecode(trim($parts[1]));
            }
        }

        return $cookies;
    }

    private static function string(mixed $value): ?string
    {
        return \is_scalar($value) ? (string) $value : null;
    }

    /**
     * Keeps the scalar entries of an Event map; list values are joined like a proxy folds repeated headers.
     *
     * @return array<string, string>
     */
    private static function stringMap(mixed $value): array
    {
        $map = [];
        foreach (\is_array($value) ? $value : [] as $key => $item) {
            if (\is_array($item)) {
                $item = implode(', ', array_filter($item, is_scalar(...)));
            }
            if (\is_scalar($item)) {
                $map[(string) $key] = (string) $item;
            }
        }

        return $map;
    }
}

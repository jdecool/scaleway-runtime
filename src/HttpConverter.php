<?php

declare(strict_types=1);

namespace JDecool\ScalewayRuntime;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Converts an Event into a Symfony Request, and a Symfony Response into a Function Response.
 *
 * @phpstan-type FunctionResponse array{statusCode: int, headers: array<string, string|list<string>>, body: string, isBase64Encoded?: true}
 */
final class HttpConverter
{
    /**
     * The address the Request sees as its peer: Scaleway always fronts the function,
     * so the peer is the platform proxy and the client comes from X-Forwarded-For.
     */
    public const string PROXY_ADDRESS = '127.0.0.1';

    public const int TRUSTED_HEADERS = Request::HEADER_X_FORWARDED_FOR
        | Request::HEADER_X_FORWARDED_HOST
        | Request::HEADER_X_FORWARDED_PORT
        | Request::HEADER_X_FORWARDED_PROTO;

    /**
     * @param array<mixed> $event
     */
    public static function toRequest(array $event): Request
    {
        $method = strtoupper(self::string($event['method'] ?? $event['httpMethod'] ?? null) ?? 'GET');
        $path = '/'.ltrim(self::string($event['path'] ?? null) ?? '', '/');
        $queryString = http_build_query(self::stringMap($event['queryStringParameters'] ?? null));
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
            'REMOTE_ADDR' => self::PROXY_ADDRESS,
            'REQUEST_TIME' => time(),
            'REQUEST_TIME_FLOAT' => microtime(true),
        ];
        $cookies = [];
        $contentType = '';
        foreach (self::stringMap($event['headers'] ?? null) as $name => $value) {
            $key = strtoupper(str_replace('-', '_', $name));
            // PHP exposes these two without the HTTP_ prefix, and HeaderBag expects them that way.
            $server[\in_array($key, ['CONTENT_TYPE', 'CONTENT_LENGTH'], true) ? $key : 'HTTP_'.$key] = $value;
            if ('COOKIE' === $key) {
                $cookies = self::parseCookies($value);
            } elseif ('CONTENT_TYPE' === $key) {
                $contentType = strtolower($value);
            }
        }

        // PHP only parses form bodies into $_POST for POST; Symfony's createFromGlobals() covers the other body methods.
        $post = [];
        if (\in_array($method, ['POST', 'PUT', 'DELETE', 'PATCH', 'QUERY'], true)
            && str_starts_with($contentType, 'application/x-www-form-urlencoded')
        ) {
            parse_str($body, $post);
        }

        return new Request($query, $post, [], $cookies, [], $server, $body);
    }

    /**
     * @return FunctionResponse
     */
    public static function toFunctionResponse(Response $response): array
    {
        $body = '';
        // A callback handler keeps the chunks even when a streamed callback calls ob_flush().
        ob_start(static function (string $chunk) use (&$body): string {
            $body .= $chunk;

            return '';
        });
        try {
            $response->sendContent();
        } finally {
            ob_end_flush();
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

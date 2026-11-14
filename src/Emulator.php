<?php

declare(strict_types=1);

namespace JDecool\ScalewayRuntime;

/**
 * Turns a real HTTP request into the Event Scaleway would pass to the Handler, as measured by the probe
 * (docs/probe-results.md); the values of "resource", "stageVariables" and "requestContext" are approximations.
 */
final class Emulator
{
    /** The local path the Emulator answers with a CRON Invocation instead of an HTTP one, as no HTTP call can be one. */
    public const string CRON_PATH = '/_scaleway/cron';

    /** The context the platform passes, with the default memory of a function. */
    public const array CONTEXT = ['memoryLimitInMb' => 256, 'functionName' => 'emulator', 'functionVersion' => ''];

    /**
     * The platform sets these itself on every HTTP call, whatever the client sends; Host never reaches the function.
     */
    private const array PLATFORM_HEADERS = ['Host', 'X-Real-Ip', 'X-Envoy-External-Address', 'X-Request-Id', 'K-Proxy-Request', 'X-Forwarded-Proto'];

    /**
     * @param array<string, string> $headers
     *
     * @return array{resource: string, path: string, httpMethod: string, headers: array<string, string>, multiValueHeaders: null, queryStringParameters: array<string, string>, multiValueQueryStringParameters: null, pathParameters: null, stageVariables: array{}, requestContext: array{httpMethod: string}, body: string, isBase64Encoded?: true}
     */
    public static function toEvent(string $method, string $uri, array $headers, string $body, string $clientAddress): array
    {
        [$path, $queryString] = explode('?', $uri, 2) + [1 => ''];
        $method = strtoupper($method);

        // A map of strings, like the platform's: the last value of a repeated key wins and "a[]" stays a plain key.
        $query = [];
        foreach (explode('&', $queryString) as $pair) {
            [$name, $value] = explode('=', $pair, 2) + [1 => ''];
            if ('' !== $name) {
                $query[urldecode($name)] = urldecode($value);
            }
        }

        $canonical = self::canonicalize($headers);
        $contentType = $canonical['Content-Type'] ?? '';
        $canonical = array_diff_key($canonical, array_flip(self::PLATFORM_HEADERS));
        // The client's X-Forwarded-For is kept in front, and its Forwarded replaces the platform's.
        $canonical['X-Forwarded-For'] = isset($canonical['X-Forwarded-For']) ? $canonical['X-Forwarded-For'].','.$clientAddress : $clientAddress;
        $canonical['Forwarded'] ??= \sprintf(str_contains($clientAddress, ':') ? 'for="[%s]";proto=https' : 'for=%s;proto=https', $clientAddress);
        $canonical += [
            'K-Proxy-Request' => 'activator',
            'X-Envoy-External-Address' => $clientAddress,
            'X-Forwarded-Proto' => 'https',
            'X-Real-Ip' => $clientAddress,
            'X-Request-Id' => self::uuid(),
        ];
        ksort($canonical);

        $event = [
            'resource' => $path,
            'path' => $path,
            'httpMethod' => $method,
            'headers' => $canonical,
            'multiValueHeaders' => null,
            'queryStringParameters' => $query,
            'multiValueQueryStringParameters' => null,
            'pathParameters' => null,
            'stageVariables' => [],
            'requestContext' => ['httpMethod' => $method],
            'body' => $body,
        ];
        // Text bodies come without the flag; empty, binary and multipart ones base64-encoded.
        if ('' === $body || 1 !== preg_match('//u', $body) || str_starts_with(strtolower($contentType), 'multipart/')) {
            $event['body'] = base64_encode($body);
            $event['isBase64Encoded'] = true;
        }

        return $event;
    }

    /**
     * The Event of a CRON trigger whose arguments are the given JSON: it comes from inside the platform, without
     * the client address headers of HTTP calls.
     *
     * @return array{path: string, httpMethod: string, headers: array<string, string>, queryStringParameters: array{}, body: string}
     */
    public static function toCronEvent(string $args): array
    {
        return [
            'path' => '/',
            'httpMethod' => 'POST',
            'headers' => [
                'Content-Length' => (string) \strlen($args),
                'Content-Type' => 'application/json',
                'Forwarded' => 'for=127.0.0.1;proto=http',
                'K-Proxy-Request' => 'activator',
                'User-Agent' => 'Go-http-client/1.1',
                'X-Forwarded-For' => '127.0.0.1',
                'X-Forwarded-Proto' => 'http',
                'X-Request-Id' => self::uuid(),
            ],
            'queryStringParameters' => [],
            'body' => $args,
        ];
    }

    /**
     * @param array<string, string> $headers
     *
     * @return array<string, string>
     */
    private static function canonicalize(array $headers): array
    {
        $canonical = [];
        foreach ($headers as $name => $value) {
            $name = ucwords(strtolower($name), '-');
            $canonical[$name] = isset($canonical[$name]) ? $canonical[$name].', '.$value : $value;
        }

        return $canonical;
    }

    private static function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = \chr(\ord($bytes[6]) & 0x0F | 0x40);
        $bytes[8] = \chr(\ord($bytes[8]) & 0x3F | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}

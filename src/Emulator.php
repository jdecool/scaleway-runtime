<?php

declare(strict_types=1);

namespace JDecool\ScalewayRuntime;

/**
 * Turns a real HTTP request into the Event Scaleway would pass to the Handler.
 *
 * Shape from https://www.scaleway.com/en/docs/serverless-functions/reference-content/code-examples/;
 * how the platform canonicalizes headers, folds repeated query keys and fills X-Forwarded-* is
 * an assumption until the probe function settles it.
 */
final class Emulator
{
    /**
     * @param array<string, string> $headers
     *
     * @return array{path: string, queryStringParameters: array<string, string>, body: string, headers: array<string, string>, method: string, isBase64Encoded: bool}
     */
    public static function toEvent(string $method, string $uri, array $headers, string $body, string $clientAddress): array
    {
        [$path, $queryString] = explode('?', $uri, 2) + [1 => ''];

        // A map of strings, like the platform's: the last value of a repeated key wins and "a[]" stays a plain key.
        $query = [];
        foreach (explode('&', $queryString) as $pair) {
            [$name, $value] = explode('=', $pair, 2) + [1 => ''];
            if ('' !== $name) {
                $query[urldecode($name)] = urldecode($value);
            }
        }

        $canonical = [];
        foreach ($headers as $name => $value) {
            $name = ucwords(strtolower($name), '-');
            $canonical[$name] = isset($canonical[$name]) ? $canonical[$name].', '.$value : $value;
        }
        // As a reverse proxy does: the peer is appended to the client's chain.
        $canonical['X-Forwarded-For'] = isset($canonical['X-Forwarded-For']) ? $canonical['X-Forwarded-For'].', '.$clientAddress : $clientAddress;
        $canonical['X-Forwarded-Proto'] = 'http';

        // JSON carries the Event, so a body that is not UTF-8 text travels base64-encoded.
        $binary = 1 !== preg_match('//u', $body);

        return [
            'path' => $path,
            'queryStringParameters' => $query,
            'body' => $binary ? base64_encode($body) : $body,
            'headers' => $canonical,
            'method' => strtoupper($method),
            'isBase64Encoded' => $binary,
        ];
    }
}

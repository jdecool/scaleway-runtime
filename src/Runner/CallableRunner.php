<?php

declare(strict_types=1);

namespace JDecool\ScalewayRuntime\Runner;

/**
 * Hands each Event and context to a raw callable, and the Function Response it returns back to Scaleway.
 *
 * Raw means raw: there is no HTTP conversion and no Command Invocation routing, CRON Invocations included;
 * the callable owns whatever state it keeps across Invocations.
 */
final readonly class CallableRunner implements InvocationRunner
{
    /**
     * @param \Closure(array<mixed>, mixed): mixed $callable
     */
    public function __construct(
        private \Closure $callable,
    ) {
    }

    public function invoke(array $event, mixed $context): array
    {
        $response = ($this->callable)($event, $context);

        // Scaleway only documents this shape for PHP, and what it makes of anything else is unknown: keys it does
        // not document are dropped rather than passed along.
        $fields = \is_array($response) ? $response : [];
        $statusCode = $fields['statusCode'] ?? null;
        $headers = $fields['headers'] ?? null;
        $body = $fields['body'] ?? null;
        $base64 = $fields['isBase64Encoded'] ?? false;
        if (!\is_int($statusCode) || !self::isHeaders($headers) || !\is_string($body) || !\is_bool($base64)) {
            throw new \UnexpectedValueException(\sprintf('A raw callable must return a Function Response: an array with an int "statusCode", "headers" mapping names to a string or a list of strings, a string "body" and an optional bool "isBase64Encoded"; "%s" returned.', get_debug_type($response)));
        }

        $functionResponse = ['statusCode' => $statusCode, 'headers' => $headers, 'body' => $body];
        if (\array_key_exists('isBase64Encoded', $fields)) {
            $functionResponse['isBase64Encoded'] = $base64;
        }

        return $functionResponse;
    }

    /**
     * @phpstan-assert-if-true array<string, string|list<string>> $headers
     */
    private static function isHeaders(mixed $headers): bool
    {
        // Closures rather than is_string(...), as array_all() passes the key too and internal functions reject extra arguments.
        return \is_array($headers) && array_all($headers, static fn (mixed $value, int|string $name): bool => \is_string($name)
            && (\is_string($value) || \is_array($value) && array_is_list($value) && array_all($value, static fn (mixed $item): bool => \is_string($item))));
    }
}

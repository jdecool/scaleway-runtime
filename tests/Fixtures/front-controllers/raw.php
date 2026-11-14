<?php

declare(strict_types=1);

use JDecool\ScalewayRuntime\Tests\Fixtures\RecordingKernel;

// Counts its builds in RecordingKernel::$builds, and fails while RecordingKernel::$failingBuilds is positive.
return static function (): Closure {
    ++RecordingKernel::$builds;
    if (RecordingKernel::$failingBuilds > 0) {
        --RecordingKernel::$failingBuilds;

        throw new RuntimeException('Build failed on purpose.');
    }
    $calls = 0;

    return static function (array $event, mixed $context) use (&$calls): mixed {
        ++$calls;

        return match ($event['path'] ?? null) {
            '/throw' => throw new LogicException('Escaped on purpose.'),
            '/invalid' => ['statusCode' => '200', 'headers' => [], 'body' => 'not an int status'],
            '/bad-header' => ['statusCode' => 200, 'headers' => ['X-Bad' => ['a' => 'b']], 'body' => ''],
            '/string' => 'plain body',
            '/null-base64' => ['statusCode' => 200, 'body' => '', 'isBase64Encoded' => null],
            '/minimal' => ['statusCode' => 200, 'body' => 'no headers'],
            default => [
                'statusCode' => 202,
                'headers' => ['Content-Type' => 'application/json', 'X-Multi' => ['a', 'b']],
                'body' => json_encode(['calls' => $calls, 'event' => $event, 'context' => $context], \JSON_THROW_ON_ERROR),
                'isBase64Encoded' => false,
                'multiValueHeaders' => [],
            ],
        };
    };
};

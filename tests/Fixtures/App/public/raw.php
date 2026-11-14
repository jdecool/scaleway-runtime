<?php

declare(strict_types=1);

require_once dirname(__DIR__).'/vendor/autoload_runtime.php';

return static function (): Closure {
    $calls = 0;

    return static function (array $event, mixed $context) use (&$calls): array {
        return [
            'statusCode' => 200,
            'headers' => ['Content-Type' => 'application/json'],
            'body' => json_encode(['event' => $event, 'calls' => ++$calls, 'pid' => getmypid()], \JSON_THROW_ON_ERROR),
        ];
    };
};

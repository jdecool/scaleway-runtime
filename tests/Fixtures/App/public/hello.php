<?php

declare(strict_types=1);

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

require_once dirname(__DIR__).'/vendor/autoload_runtime.php';

return static function (Request $request, array $context): JsonResponse {
    /** @var int $calls */
    static $calls = 0;

    return new JsonResponse([
        'message' => 'Hello '.$request->query->getString('name').'!',
        'secure' => $request->isSecure(),
        'env' => $context['APP_ENV'],
        'calls' => ++$calls,
        'pid' => getmypid(),
    ]);
};

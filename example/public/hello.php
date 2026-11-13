<?php

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

require_once dirname(__DIR__).'/vendor/autoload_runtime.php';

// A function-like Front Controller: no Kernel, called for every Invocation with its Request.
return static function (Request $request): JsonResponse {
    return new JsonResponse(['message' => sprintf('Hello %s!', $request->query->get('name', 'World'))]);
};

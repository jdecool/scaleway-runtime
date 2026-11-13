<?php

declare(strict_types=1);

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

return static fn (Request $request): ?Response => '/nothing' === $request->getPathInfo()
    ? null
    : new Response(sprintf('Hello %s from %s', $request->query->getString('name'), $request->getClientIp()));

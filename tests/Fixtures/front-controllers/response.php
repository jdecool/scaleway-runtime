<?php

declare(strict_types=1);

use Symfony\Component\HttpFoundation\Response;

return static function (): Response {
    /** @var int $calls */
    static $calls = 0;

    return new Response('call #'.++$calls);
};

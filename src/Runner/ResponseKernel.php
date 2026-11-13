<?php

declare(strict_types=1);

namespace JDecool\ScalewayRuntime\Runner;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * Serves a Front Controller returning a Response, so that HttpKernelRunner handles it like any Kernel.
 *
 * @internal
 */
final class ResponseKernel implements HttpKernelInterface
{
    /**
     * @param \Closure(Request): mixed $respond calls the Front Controller closure for one Request
     * @param Response|null            $first   the Response the closure already returned for the first Invocation
     */
    public function __construct(
        private readonly \Closure $respond,
        private ?Response $first = null,
    ) {
    }

    public function handle(Request $request, int $type = self::MAIN_REQUEST, bool $catch = true): Response
    {
        $response = $this->first ?? ($this->respond)($request);
        $this->first = null;
        if (!$response instanceof Response) {
            throw new \LogicException(\sprintf('A Front Controller returning a Response must return one for every Invocation, "%s" returned.', get_debug_type($response)));
        }

        return $response;
    }
}

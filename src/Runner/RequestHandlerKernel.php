<?php

declare(strict_types=1);

namespace JDecool\ScalewayRuntime\Runner;

use Psr\Http\Server\RequestHandlerInterface;
use Symfony\Bridge\PsrHttpMessage\Factory\HttpFoundationFactory;
use Symfony\Bridge\PsrHttpMessage\Factory\PsrHttpFactory;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Serves a PSR-15 Application, so that HttpKernelRunner handles it like any Kernel.
 *
 * @internal
 */
final class RequestHandlerKernel implements HttpKernelInterface
{
    private readonly PsrHttpFactory $psrFactory;
    private readonly HttpFoundationFactory $httpFoundationFactory;
    private bool $handled = false;

    public function __construct(
        private readonly RequestHandlerInterface $handler,
    ) {
        if (!class_exists(PsrHttpFactory::class)) {
            throw new \LogicException(\sprintf('Running a PSR-15 Application ("%s") requires the PSR-7 bridge, try running "composer require symfony/psr-http-message-bridge nyholm/psr7".', get_debug_type($handler)));
        }

        // Without a PSR-17 factory given, the bridge picks nyholm/psr7 or php-http/discovery, and explains how to get one.
        $this->psrFactory = new PsrHttpFactory();
        $this->httpFoundationFactory = new HttpFoundationFactory();
    }

    public function handle(Request $request, int $type = self::MAIN_REQUEST, bool $catch = true): Response
    {
        // Before every Invocation but the first, like the Kernel's services_resetter, so a failed one is cleaned up too.
        if ($this->handled && $this->handler instanceof ResetInterface) {
            $this->handler->reset();
        }
        $this->handled = true;

        // PSR-15 apps read the client from the server params and do not know the trusted proxies, so they get
        // the request as the platform proxy received it, the way mod_remoteip presents it to PHP.
        $server = [
            'REMOTE_ADDR' => $request->getClientIp(),
            // PSR-7 needs a parsable URI; an Event without Host gets the default of Request::create().
            'HTTP_HOST' => '' === $request->getHost() ? 'localhost' : $request->getHttpHost(),
            'SERVER_PORT' => $request->getPort() ?? ($request->isSecure() ? 443 : 80),
            'HTTPS' => $request->isSecure() ? 'on' : 'off',
        ] + $request->server->all();

        $response = $this->handler->handle($this->psrFactory->createRequest($request->duplicate(server: $server)));

        return $this->httpFoundationFactory->createResponse($response);
    }
}

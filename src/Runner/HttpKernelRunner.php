<?php

declare(strict_types=1);

namespace JDecool\ScalewayRuntime\Runner;

use JDecool\ScalewayRuntime\HttpConverter;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\HttpKernel\TerminableInterface;

final readonly class HttpKernelRunner implements InvocationRunner
{
    public function __construct(
        private HttpKernelInterface $kernel,
    ) {
        // Booting here makes a broken container a boot failure, so the Handler retries with a fresh Application.
        if ($kernel instanceof KernelInterface) {
            $kernel->boot();
        }

        // The Kernel applies the app's own trusted_proxies while booting; only fill the gap when it set none.
        if ([] === Request::getTrustedProxies()) {
            Request::setTrustedProxies([HttpConverter::PROXY_ADDRESS], HttpConverter::TRUSTED_HEADERS);
        }
    }

    public function invoke(array $event, mixed $context): array
    {
        $request = HttpConverter::toRequest($event);
        // The Kernel resets its services itself (services_resetter) when the next main request is handled.
        $response = $this->kernel->handle($request);

        try {
            return HttpConverter::toFunctionResponse($response);
        } finally {
            if ($this->kernel instanceof TerminableInterface) {
                $this->kernel->terminate($request, $response);
            }
        }
    }
}

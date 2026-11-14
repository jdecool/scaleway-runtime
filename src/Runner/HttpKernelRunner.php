<?php

declare(strict_types=1);

namespace JDecool\ScalewayRuntime\Runner;

use JDecool\ScalewayRuntime\HttpConverter;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\HttpKernel\TerminableInterface;

final readonly class HttpKernelRunner implements InvocationRunner
{
    /**
     * @param resource $errorLog
     * @param string   $host     the public host of the function
     */
    public function __construct(
        private HttpKernelInterface $kernel,
        private mixed $errorLog,
        private string $host = 'localhost',
    ) {
        // Booting here makes a broken container a boot failure, so the Handler retries with a fresh Application.
        if ($kernel instanceof KernelInterface) {
            $kernel->boot();
        }
    }

    public function invoke(array $event, mixed $context): array
    {
        $request = HttpConverter::toRequest($event, $this->host);
        // Captured before the Application can alter the bag, so every temp file gets deleted.
        $uploads = $request->files->all();
        try {
            // The Kernel resets its services itself (services_resetter) when the next main request is handled.
            $response = $this->kernel->handle($request);
            $functionResponse = HttpConverter::toFunctionResponse($response);

            if ($this->kernel instanceof TerminableInterface) {
                try {
                    $this->kernel->terminate($request, $response);
                } catch (\Throwable $e) {
                    // Behind FPM the client already got the Response when terminate() runs; turning it into
                    // a 500 would invite retries of an operation that succeeded.
                    fwrite($this->errorLog, 'Kernel termination failed: '.$e.\PHP_EOL);
                }
            }

            return $functionResponse;
        } finally {
            // PHP deletes uploaded files at the end of each request; a Warm Process must do it itself.
            array_walk_recursive($uploads, static function (mixed $file): void {
                if ($file instanceof UploadedFile && is_file($file->getPathname())) {
                    unlink($file->getPathname());
                }
            });
        }
    }
}

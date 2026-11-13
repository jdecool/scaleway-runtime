<?php

declare(strict_types=1);

namespace JDecool\ScalewayRuntime\Tests\Fixtures;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\TerminableInterface;

final class RecordingKernel implements HttpKernelInterface, TerminableInterface
{
    public static int $builds = 0;
    public static int $failingBuilds = 0;
    public static ?self $last = null;

    public int $handled = 0;
    public int $terminated = 0;

    public static function build(): self
    {
        ++self::$builds;
        if (self::$failingBuilds > 0) {
            --self::$failingBuilds;

            throw new \RuntimeException('Build failed on purpose.');
        }

        return self::$last = new self();
    }

    public static function reset(): void
    {
        self::$builds = self::$failingBuilds = 0;
        self::$last = null;
    }

    public function handle(Request $request, int $type = self::MAIN_REQUEST, bool $catch = true): Response
    {
        if ('/throw' === $request->getPathInfo()) {
            throw new \LogicException('Escaped on purpose.');
        }

        return new Response('handled #'.++$this->handled.' '.$request->getPathInfo());
    }

    public function terminate(Request $request, Response $response): void
    {
        ++$this->terminated;
        if ('/terminate-fails' === $request->getPathInfo()) {
            throw new \LogicException('Termination failed on purpose.');
        }
    }
}

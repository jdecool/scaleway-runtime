<?php

declare(strict_types=1);

namespace JDecool\ScalewayRuntime;

use JDecool\ScalewayRuntime\Runner\HttpKernelRunner;
use JDecool\ScalewayRuntime\Runner\InvocationRunner;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Runtime\SymfonyRuntime;

/**
 * Runs Symfony Applications on Scaleway Serverless Functions.
 *
 * On top of the options managed by SymfonyRuntime, it accepts:
 *  - "front_controller": the Front Controller path, relative to "project_dir"; defaults to "public/index.php".
 *
 * When the project's var/ directory is not writable (the deployed code may be read-only),
 * the cache and log dirs move to the system temp dir through APP_CACHE_DIR and APP_LOG_DIR,
 * while APP_BUILD_DIR keeps the container warmed at build time.
 */
class ScalewayRuntime extends SymfonyRuntime
{
    /**
     * @param array<mixed> $options see SymfonyRuntime, plus "front_controller"
     */
    public function __construct(array $options = [])
    {
        parent::__construct($options); // @phpstan-ignore argument.type (options come from untyped config)

        $projectDir = $this->options['project_dir'] ?? null;
        if (\is_string($projectDir) && !is_writable(is_dir($projectDir.'/var') ? $projectDir.'/var' : $projectDir)) {
            // Kernels using MicroKernelTrait read these from $_SERVER only; values set by the app win.
            $_SERVER['APP_BUILD_DIR'] ??= $projectDir.'/var/cache';
            $_SERVER['APP_CACHE_DIR'] ??= sys_get_temp_dir().'/cache';
            $_SERVER['APP_LOG_DIR'] ??= sys_get_temp_dir().'/log';
        }
    }

    /**
     * Builds the Application from the Front Controller and picks the runner matching its type.
     *
     * @param resource $errorLog where failures that must not change the Function Response are reported
     */
    public function createInvocationRunner(mixed $errorLog): InvocationRunner
    {
        $projectDir = $this->options['project_dir'] ?? null;
        $frontController = $this->options['front_controller'] ?? 'public/index.php';
        if (!\is_string($projectDir) || !\is_string($frontController)) {
            throw new \LogicException('The "project_dir" and "front_controller" runtime options must be strings.');
        }

        $file = $projectDir.'/'.$frontController;
        // A static closure keeps $this and our locals out of the Front Controller's scope.
        $app = (static fn (): mixed => require $file)();
        if (!\is_callable($app)) {
            throw new \TypeError(\sprintf('Invalid return value: callable expected, "%s" returned from "%s".', get_debug_type($app), $file));
        }

        [$app, $args] = $this->getResolver($app)->resolve();
        $application = $app(...$args);

        return match (true) {
            $application instanceof HttpKernelInterface => new HttpKernelRunner($application, $errorLog),
            default => throw new \LogicException(\sprintf('"%s" cannot run Applications of type "%s".', static::class, get_debug_type($application))),
        };
    }
}

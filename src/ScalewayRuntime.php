<?php

declare(strict_types=1);

namespace JDecool\ScalewayRuntime;

use JDecool\ScalewayRuntime\Runner\CallableRunner;
use JDecool\ScalewayRuntime\Runner\ConsoleRunner;
use JDecool\ScalewayRuntime\Runner\HttpKernelRunner;
use JDecool\ScalewayRuntime\Runner\InvocationRunner;
use JDecool\ScalewayRuntime\Runner\RequestHandlerKernel;
use JDecool\ScalewayRuntime\Runner\ResponseKernel;
use Psr\Http\Server\RequestHandlerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application as FrameworkApplication;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\Runtime\SymfonyRuntime;

/**
 * Runs Symfony Applications on Scaleway Serverless Functions.
 *
 * On top of the options managed by SymfonyRuntime, it accepts:
 *  - "front_controller": the Front Controller path, relative to "project_dir"; defaults to "public/index.php";
 *  - "host": the public host of the function, as no Host header reaches it; defaults to the SCALEWAY_HOST
 *    environment variable, then to "localhost".
 *
 * Unlike SymfonyRuntime, it defaults the env to prod when the process environment sets none, whatever .env files say.
 *
 * When the project's var/ directory is not writable (the deployed code may be read-only),
 * the cache and log dirs move to the system temp dir through APP_CACHE_DIR and APP_LOG_DIR,
 * while APP_BUILD_DIR keeps the container warmed at build time.
 *
 * A Front Controller closure returning a Response is called again for every Invocation, and one
 * taking a Request gets the Invocation's Request (see docs/adr/0004-call-response-front-controllers-per-invocation.md).
 * A PSR-15 request handler is built once and reused like a Kernel; it needs symfony/psr-http-message-bridge and nyholm/psr7.
 *
 * Command Invocations run through the Console Application of a Kernel, or of a Front Controller returning a
 * Console Application or a Command, which then answers any other Invocation with a 403.
 *
 * A Closure returned by the Front Controller closure is a raw callable, built once and reused like a Kernel:
 * it gets every Event and context as they are, CRON Invocations included, and returns the Function Response.
 */
class ScalewayRuntime extends SymfonyRuntime
{
    public const string HOST_VAR = 'SCALEWAY_HOST';

    /** The Request of the Invocation the Front Controller closure is being called for. */
    private ?Request $request = null;

    /**
     * @param array<mixed> $options see SymfonyRuntime, plus "front_controller" and "host"
     */
    public function __construct(array $options = [])
    {
        $envKey = \is_string($options['env_var_name'] ?? null) ? $options['env_var_name'] : 'APP_ENV';
        // The shipped .env says dev, whose error pages leak stack traces: only the function's own environment,
        // or the "env" option, may choose another env than prod (see docs/adr/0006-default-to-the-prod-env.md).
        $options['env'] ??= ($_SERVER[$envKey] ?? null) ?: ($_ENV[$envKey] ?? null) ?: getenv($envKey) ?: 'prod';

        parent::__construct($options); // @phpstan-ignore argument.type (options come from untyped config)

        // Invocations are web requests served by a long-lived process, as in FrankenPHP's worker mode; in a CLI SAPI,
        // FrameworkBundle would otherwise render error pages with its CLI renderer, which dumps the exception even in prod.
        $_SERVER['APP_RUNTIME_MODE'] ??= 'web=1&worker=1';

        // Read after Dotenv ran, so .env files may set it like any other variable.
        $this->options['host'] ??= ($_SERVER[self::HOST_VAR] ?? null) ?: ($_ENV[self::HOST_VAR] ?? null) ?: getenv(self::HOST_VAR) ?: 'localhost';

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

        $resolver = $this->getResolver($app);
        $respond = function (Request $request) use ($resolver): mixed {
            $this->request = $request;
            try {
                [$app, $args] = $resolver->resolve();

                return $app(...$args);
            } finally {
                $this->request = null;
            }
        };

        // No Request exists before the first Invocation, so such a closure cannot be called yet.
        foreach (new \ReflectionFunction($app(...))->getParameters() as $parameter) {
            $type = $parameter->getType();
            if ($type instanceof \ReflectionNamedType && Request::class === $type->getName()) {
                return $this->withCommands(new ResponseKernel($respond), $app(...), $errorLog);
            }
        }

        [$app, $args] = $resolver->resolve();
        $application = $app(...$args);

        // GenericRuntime would run a returned Closure as a script taking no arguments, which means nothing per
        // Invocation; objects keep being dispatched on their type, so an invokable one is never taken for it.
        if ($application instanceof \Closure) {
            return new CallableRunner($application);
        }

        if ($application instanceof Command) {
            $command = $application;
            $application = new Application();
            // SymfonyRuntime names a nameless command after argv[0], the script path: the Front Controller here.
            if (!$command->getName()) {
                $command->setName($frontController);
            }
            $application->addCommand($command);
            $application->setDefaultCommand((string) $command->getName(), true);
        }
        if ($application instanceof Application) {
            // FrameworkBundle's Application boots its Kernel on its first run only, and never again once that boot
            // failed: booting here makes it a boot failure the Handler retries, as for a Kernel.
            if ($application instanceof FrameworkApplication) {
                $application->getKernel()->boot();
            }

            return new ConsoleRunner(static fn (): Application => $application, null, $errorLog);
        }

        $kernel = match (true) {
            $application instanceof HttpKernelInterface => $application,
            $application instanceof Response => new ResponseKernel($respond, $application),
            $application instanceof RequestHandlerInterface => new RequestHandlerKernel($application),
            default => throw new \LogicException(\sprintf('"%s" cannot run Applications of type "%s".', static::class, get_debug_type($application))),
        };

        return $this->withCommands($kernel, $application, $errorLog);
    }

    /**
     * Serves an HTTP Application, which also runs Command Invocations when it is a Kernel.
     *
     * @param resource $errorLog
     */
    private function withCommands(HttpKernelInterface $kernel, object $application, mixed $errorLog): ConsoleRunner
    {
        return new ConsoleRunner(static function () use ($kernel, $application): Application {
            if (!$kernel instanceof KernelInterface) {
                throw new \LogicException(\sprintf('Command Invocations need a Front Controller returning a Kernel, a Console Application or a Command, not "%s".', get_debug_type($application)));
            }
            // Checked in this order since FrameworkBundle's Application extends the Console one, which may be missing.
            if (!class_exists(Application::class) || !class_exists(FrameworkApplication::class)) {
                throw new \LogicException('Running Command Invocations through a Kernel requires symfony/console and symfony/framework-bundle.');
            }

            return new FrameworkApplication($kernel);
        }, new HttpKernelRunner($kernel, $errorLog, $this->host()), $errorLog);
    }

    private function host(): string
    {
        $host = $this->options['host'] ?? null;

        return \is_string($host) ? $host : throw new \LogicException('The "host" runtime option must be a string.');
    }

    protected function getArgument(\ReflectionParameter $parameter, ?string $type): mixed
    {
        // SymfonyRuntime builds it from PHP globals, which never describe an Invocation; resolveType()
        // would be the narrower hook but only exists from symfony/runtime 8.1.
        return Request::class === $type ? $this->request : parent::getArgument($parameter, $type);
    }
}

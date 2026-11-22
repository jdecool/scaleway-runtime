<?php

declare(strict_types=1);

namespace JDecool\ScalewayRuntime;

use Composer\InstalledVersions;
use JDecool\ScalewayRuntime\Runner\InvocationRunner;

/**
 * Backs the global handle() function Scaleway calls for each Invocation.
 *
 * The Application is built once per Warm Process and reused; when building it fails,
 * the next Invocation tries again from scratch instead of keeping a half-booted Application.
 *
 * @phpstan-import-type FunctionResponse from HttpConverter
 */
final class Handler
{
    private static ?self $instance = null;

    private ?ScalewayRuntime $runtime = null;
    private ?StaticFiles $staticFiles = null;
    private ?InvocationRunner $runner = null;

    /**
     * @param string   $composerDir the directory holding the application's composer.json
     * @param resource $errorLog
     */
    public function __construct(
        private readonly string $composerDir,
        private $errorLog,
    ) {
    }

    /**
     * @param array<mixed> $event
     *
     * @return FunctionResponse
     */
    public static function handle(array $event, mixed $context): array
    {
        if (null === self::$instance) {
            $errorLog = fopen('php://stderr', 'w');
            \assert(false !== $errorLog);
            self::$instance = new self((string) realpath(InstalledVersions::getRootPackage()['install_path']), $errorLog);
        }

        return self::$instance->invoke($event, $context);
    }

    /**
     * @param array<mixed> $event
     *
     * @return FunctionResponse
     */
    public function invoke(array $event, mixed $context): array
    {
        try {
            $this->runtime ??= new ScalewayRuntime($this->runtimeOptions());
            // Before the Application is built, so Static Files cost no boot and are served even when it cannot be built.
            $this->staticFiles ??= $this->runtime->createStaticFiles($this->errorLog);
            if (null !== $response = $this->staticFiles->serve($event)) {
                return $response;
            }
            $this->runner ??= $this->runtime->createInvocationRunner($this->errorLog);

            return $this->runner->invoke($event, $context);
        } catch (\Throwable $e) {
            fwrite($this->errorLog, 'Invocation failed: '.$e.\PHP_EOL);

            $debug = filter_var($_SERVER['APP_DEBUG'] ?? $_ENV['APP_DEBUG'] ?? false, \FILTER_VALIDATE_BOOL);

            return [
                'statusCode' => 500,
                'headers' => ['Content-Type' => 'text/plain; charset=UTF-8'],
                'body' => $debug ? (string) $e : 'Internal Server Error',
            ];
        }
    }

    /**
     * Gathers the options the same way vendor/autoload_runtime.php does: APP_RUNTIME_OPTIONS
     * wins over composer.json extra.runtime, whose "project_dir" is relative to composer.json.
     *
     * @return array<mixed>
     */
    private function runtimeOptions(): array
    {
        $extra = [];
        $composerFile = $this->composerDir.'/composer.json';
        if (is_file($composerFile)) {
            $composer = json_decode((string) file_get_contents($composerFile), true, 512, \JSON_THROW_ON_ERROR);
            $runtime = \is_array($composer) && \is_array($composer['extra'] ?? null) ? $composer['extra']['runtime'] ?? null : null;
            $extra = \is_array($runtime) ? $runtime : [];
        }

        $projectDir = $this->composerDir.'/'.(\is_string($extra['project_dir'] ?? null) ? $extra['project_dir'] : '');
        unset($extra['class'], $extra['autoload_template'], $extra['project_dir']);

        $options = $_SERVER['APP_RUNTIME_OPTIONS'] ?? $_ENV['APP_RUNTIME_OPTIONS'] ?? [];
        if (\is_string($options)) {
            $options = json_decode($options, true, 512, \JSON_THROW_ON_ERROR);
        }
        if (!\is_array($options)) {
            throw new \UnexpectedValueException('APP_RUNTIME_OPTIONS must be a JSON object.');
        }

        return $options + $extra + ['project_dir' => realpath($projectDir) ?: $projectDir];
    }
}

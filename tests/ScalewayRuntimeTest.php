<?php

declare(strict_types=1);

namespace JDecool\ScalewayRuntime\Tests;

use JDecool\ScalewayRuntime\ScalewayRuntime;
use PHPUnit\Framework\TestCase;

final class ScalewayRuntimeTest extends TestCase
{
    /** @var array<mixed> */
    private array $server;
    /** @var array<mixed> */
    private array $env;
    private string|false $processEnv;
    private int $umask;
    private string $projectDir;

    protected function setUp(): void
    {
        $this->server = $_SERVER;
        $this->env = $_ENV;
        $this->processEnv = getenv('APP_ENV');
        // GenericRuntime resets the umask in debug mode.
        $this->umask = umask();
        unset($_SERVER['APP_BUILD_DIR'], $_SERVER['APP_CACHE_DIR'], $_SERVER['APP_LOG_DIR']);
        // As on a function without APP_ENV, whatever the environment running the tests sets.
        unset($_SERVER['APP_ENV'], $_ENV['APP_ENV'], $_SERVER['APP_DEBUG'], $_ENV['APP_DEBUG']);
        putenv('APP_ENV');
        $this->projectDir = sys_get_temp_dir().'/scaleway-runtime-'.bin2hex(random_bytes(4));
        mkdir($this->projectDir.'/var', 0o777, true);
        file_put_contents($this->projectDir.'/.env', "APP_ENV=dev\n");
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->server;
        $_ENV = $this->env;
        putenv(false === $this->processEnv ? 'APP_ENV' : 'APP_ENV='.$this->processEnv);
        umask($this->umask);
        chmod($this->projectDir, 0o777);
        if (is_dir($this->projectDir.'/var')) {
            chmod($this->projectDir.'/var', 0o777);
            rmdir($this->projectDir.'/var');
        }
        unlink($this->projectDir.'/.env');
        rmdir($this->projectDir);
    }

    public function testUnsetEnvDefaultsToProdWithoutDebugWhateverTheDotenvFileSays(): void
    {
        $this->createRuntime(['debug' => null]);

        self::assertSame('prod', $_SERVER['APP_ENV']);
        self::assertSame('0', $_SERVER['APP_DEBUG']);
    }

    public function testEmptyEnvCountsAsUnset(): void
    {
        $_SERVER['APP_ENV'] = '';

        $this->createRuntime(['debug' => null]);

        self::assertSame('prod', $_SERVER['APP_ENV']);
    }

    public function testEnvOfTheProcessWins(): void
    {
        $_SERVER['APP_ENV'] = 'dev';

        $this->createRuntime(['debug' => null]);

        self::assertSame('dev', $_SERVER['APP_ENV']);
        self::assertSame('1', $_SERVER['APP_DEBUG']);
    }

    public function testEnvOnlyVisibleThroughGetenvWins(): void
    {
        putenv('APP_ENV=staging');

        $this->createRuntime(['debug' => null]);

        self::assertSame('staging', $_SERVER['APP_ENV']);
    }

    public function testEnvOptionWins(): void
    {
        $_SERVER['APP_ENV'] = 'dev';

        $this->createRuntime(['env' => 'test']);

        self::assertSame('test', $_SERVER['APP_ENV']);
    }

    public function testDebugSetByTheProcessWinsInProd(): void
    {
        $_SERVER['APP_DEBUG'] = '1';

        $this->createRuntime(['debug' => null]);

        self::assertSame('prod', $_SERVER['APP_ENV']);
        self::assertSame('1', $_SERVER['APP_DEBUG']);
    }

    public function testWritableProjectKeepsItsDirs(): void
    {
        $this->createRuntime();

        self::assertArrayNotHasKey('APP_CACHE_DIR', $_SERVER);
        self::assertArrayNotHasKey('APP_BUILD_DIR', $_SERVER);
        self::assertArrayNotHasKey('APP_LOG_DIR', $_SERVER);
    }

    public function testReadOnlyVarDirFallsBackToTempDirAndKeepsTheWarmedBuildDir(): void
    {
        chmod($this->projectDir.'/var', 0o555);

        $this->createRuntime();

        self::assertSame($this->projectDir.'/var/cache', $_SERVER['APP_BUILD_DIR']);
        self::assertSame(sys_get_temp_dir().'/cache', $_SERVER['APP_CACHE_DIR']);
        self::assertSame(sys_get_temp_dir().'/log', $_SERVER['APP_LOG_DIR']);
    }

    public function testReadOnlyProjectWithoutVarDirFallsBack(): void
    {
        rmdir($this->projectDir.'/var');
        chmod($this->projectDir, 0o555);

        $this->createRuntime();

        self::assertSame(sys_get_temp_dir().'/cache', $_SERVER['APP_CACHE_DIR']);
    }

    public function testDirsSetByTheAppWin(): void
    {
        chmod($this->projectDir.'/var', 0o555);
        $_SERVER['APP_CACHE_DIR'] = '/custom/cache';

        $this->createRuntime();

        self::assertSame('/custom/cache', $_SERVER['APP_CACHE_DIR']);
    }

    /**
     * @param array<string, mixed> $options
     */
    private function createRuntime(array $options = []): void
    {
        new ScalewayRuntime($options + ['project_dir' => $this->projectDir, 'error_handler' => false, 'debug' => false]);
    }
}

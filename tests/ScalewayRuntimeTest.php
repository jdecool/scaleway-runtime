<?php

declare(strict_types=1);

namespace JDecool\ScalewayRuntime\Tests;

use JDecool\ScalewayRuntime\ScalewayRuntime;
use PHPUnit\Framework\TestCase;

final class ScalewayRuntimeTest extends TestCase
{
    /** @var array<mixed> */
    private array $server;
    private string $projectDir;

    protected function setUp(): void
    {
        $this->server = $_SERVER;
        unset($_SERVER['APP_BUILD_DIR'], $_SERVER['APP_CACHE_DIR'], $_SERVER['APP_LOG_DIR']);
        $this->projectDir = sys_get_temp_dir().'/scaleway-runtime-'.bin2hex(random_bytes(4));
        mkdir($this->projectDir.'/var', 0o777, true);
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->server;
        chmod($this->projectDir, 0o777);
        if (is_dir($this->projectDir.'/var')) {
            chmod($this->projectDir.'/var', 0o777);
            rmdir($this->projectDir.'/var');
        }
        rmdir($this->projectDir);
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

    private function createRuntime(): void
    {
        new ScalewayRuntime(['project_dir' => $this->projectDir, 'error_handler' => false, 'debug' => false]);
    }
}

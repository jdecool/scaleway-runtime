<?php

declare(strict_types=1);

namespace JDecool\ScalewayRuntime\Tests;

use JDecool\ScalewayRuntime\Packager;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

final class PackagerTest extends TestCase
{
    private Filesystem $filesystem;
    private string $dir;
    private string $appDir;

    protected function setUp(): void
    {
        $this->filesystem = new Filesystem();
        // Real paths, as bin/scaleway-package passes and as the build dir reports them (macOS /var is a symlink).
        $this->dir = (string) realpath(sys_get_temp_dir()).'/scaleway-runtime-'.bin2hex(random_bytes(4));
        $this->appDir = $this->dir.'/project/app';
        $this->filesystem->mirror(__DIR__.'/Fixtures/PackagedApp', $this->dir.'/project');
        $this->filesystem->mkdir($this->dir.'/tmp');
    }

    protected function tearDown(): void
    {
        $this->filesystem->remove($this->dir);
    }

    #[TestWith(['vendor'])]
    #[TestWith(['var'])]
    #[TestWith(['tests'])]
    #[TestWith(['.env.local'])]
    #[TestWith(['.env.prod.local'])]
    #[TestWith(['.git'])]
    #[TestWith(['assets/node_modules'])]
    #[TestWith(['terraform/.terraform'])]
    #[TestWith(['terraform/terraform.tfstate'])]
    #[TestWith(['terraform/terraform.tfstate.backup'])]
    public function testExcluded(string $path): void
    {
        self::assertTrue(Packager::isExcluded($path));
    }

    #[TestWith(['.env'])]
    #[TestWith(['.env.prod'])]
    #[TestWith(['src/vendor'])]
    #[TestWith(['src/Tests/Fixture.php'])]
    #[TestWith(['config/packages/var.yaml'])]
    #[TestWith(['public/index.php'])]
    #[TestWith(['handler.php'])]
    public function testIncluded(string $path): void
    {
        self::assertFalse(Packager::isExcluded($path));
    }

    public function testRelativePathRepositoriesAreResolvedAgainstTheApplication(): void
    {
        $lock = [
            'packages' => [
                ['name' => 'acme/local', 'dist' => ['type' => 'path', 'url' => '../lib']],
                ['name' => 'acme/absolute', 'dist' => ['type' => 'path', 'url' => '/opt/lib']],
                ['name' => 'acme/remote', 'dist' => ['type' => 'zip', 'url' => 'https://example.test/a.zip']],
            ],
            'packages-dev' => [['name' => 'acme/dev', 'dist' => ['type' => 'path', 'url' => 'packages/dev']]],
            'content-hash' => 'abc',
        ];

        $expected = $lock;
        $expected['packages'][0]['dist']['url'] = '/srv/app/../lib';
        $expected['packages-dev'][0]['dist']['url'] = '/srv/app/packages/dev';

        self::assertSame($expected, Packager::resolvePathRepositories($lock, '/srv/app'));
    }

    public function testZipShipsTheApplicationWithProductionDependenciesAndTheCommittedLock(): void
    {
        $this->filesystem->dumpFile($this->appDir.'/var/cache/prod/stale.php', '');
        $this->filesystem->dumpFile($this->appDir.'/vendor/stale.php', '');
        $this->filesystem->dumpFile($this->appDir.'/tests/ServiceTest.php', '');
        $this->filesystem->dumpFile($this->appDir.'/.env.local', 'APP_SECRET=local');
        $this->filesystem->dumpFile($this->appDir.'/.git/HEAD', '');
        $this->filesystem->dumpFile($this->appDir.'/assets/node_modules/a.js', '');
        $this->filesystem->dumpFile($this->appDir.'/terraform/terraform.tfstate', '{}');
        // A previous build, in the application like the default "function.zip": it must not end up in itself.
        $this->filesystem->dumpFile($this->appDir.'/function.zip', 'stale');
        $lock = (string) file_get_contents($this->appDir.'/composer.lock');

        [$exitCode, $log] = $this->package($this->appDir.'/function.zip');

        self::assertSame(0, $exitCode, $log);
        $zip = new \ZipArchive();
        self::assertTrue($zip->open($this->appDir.'/function.zip'));
        $names = [];
        for ($i = 0; $i < $zip->numFiles; ++$i) {
            $names[] = (string) $zip->getNameIndex($i);
        }
        $shipped = array_values(array_filter($names, static fn (string $name): bool => !str_starts_with($name, 'vendor/composer/')));
        sort($shipped);
        self::assertSame([
            '.env',
            'bin/console',
            'composer.json',
            'composer.lock',
            'handler.php',
            'src/Service.php',
            'vendor/acme/lib/composer.json',
            'vendor/autoload.php',
        ], $shipped);
        // Relative "../lib" did not resolve from the build dir, yet the zip ships the lock exactly as committed.
        self::assertSame($lock, $zip->getFromName('composer.lock'));
        self::assertSame($lock, file_get_contents($this->appDir.'/composer.lock'));
        self::assertTrue($zip->getExternalAttributesName('bin/console', $system, $attributes));
        self::assertIsInt($attributes);
        self::assertSame(0o755, ($attributes >> 16) & 0o777);
        $zip->close();

        $record = $this->record();
        self::assertSame(['cache:warmup', '--no-interaction'], $record['argv']);
        self::assertSame('prod', $record['appEnv']);
        self::assertSame($lock, $record['lock']);
    }

    public function testBuildDirIsPrivateAndRemovedAfterwards(): void
    {
        [$exitCode, $log] = $this->package($this->dir.'/function.zip');

        self::assertSame(0, $exitCode, $log);
        $record = $this->record();
        self::assertIsString($record['buildDir']);
        self::assertStringStartsWith($this->dir.'/tmp/scaleway-package-', $record['buildDir']);
        self::assertSame(0o700, $record['perms']);
        self::assertSame([], $this->leftovers());
    }

    public function testFailedBuildLeavesNeitherBuildDirNorZip(): void
    {
        [$exitCode, $log] = $this->package($this->dir.'/function.zip', ['PACKAGER_TEST_FAIL' => '1']);

        self::assertNotSame(0, $exitCode);
        self::assertStringContainsString('cache:warmup --no-interaction" failed with exit code 1', $log);
        self::assertFileDoesNotExist($this->dir.'/function.zip');
        self::assertSame([], $this->leftovers());
    }

    public function testApplicationWithoutCommittedLockIsRefused(): void
    {
        $this->filesystem->remove($this->appDir.'/composer.lock');
        $log = fopen('php://memory', 'w');
        \assert(false !== $log);

        $this->expectExceptionMessage('"composer.lock" is missing');
        new Packager($this->appDir, $log)->package($this->dir.'/function.zip');
    }

    /**
     * Runs in its own PHP process, whose sys_temp_dir is private to the test so leftovers are observable.
     *
     * @param array<string, string> $env
     *
     * @return array{int, string} the exit code and the log
     */
    private function package(string $zipFile, array $env = []): array
    {
        $code = \sprintf('require %s; (new %s(%s, STDERR))->package(%s);', var_export(\dirname(__DIR__).'/vendor/autoload.php', true), Packager::class, var_export($this->appDir, true), var_export($zipFile, true));
        $env += [
            'PACKAGER_TEST_RECORD' => $this->dir.'/record.json',
            // Hermetic: path repositories only, and no global Composer config.
            'COMPOSER_HOME' => $this->dir.'/composer-home',
            'COMPOSER_CACHE_DIR' => $this->dir.'/composer-cache',
            'COMPOSER_DISABLE_NETWORK' => '1',
            'COMPOSER_NO_AUDIT' => '1',
        ];
        $process = proc_open([\PHP_BINARY, '-d', 'sys_temp_dir='.$this->dir.'/tmp', '-r', $code], [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env + getenv());
        \assert(false !== $process);
        $log = (string) stream_get_contents($pipes[2]);
        fclose($pipes[2]);

        return [proc_close($process), $log];
    }

    /**
     * @return array<mixed>
     */
    private function record(): array
    {
        $record = json_decode((string) file_get_contents($this->dir.'/record.json'), true, 512, \JSON_THROW_ON_ERROR);
        \assert(\is_array($record));

        return $record;
    }

    /**
     * @return list<string>
     */
    private function leftovers(): array
    {
        return array_values(array_diff(scandir($this->dir.'/tmp') ?: [], ['.', '..']));
    }
}

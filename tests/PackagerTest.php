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
    /** @var resource */
    private $log;

    protected function setUp(): void
    {
        $this->filesystem = new Filesystem();
        // Real paths, as bin/scaleway-package passes them (macOS /var is a symlink).
        $this->dir = (string) realpath(sys_get_temp_dir()).'/scaleway-runtime-'.bin2hex(random_bytes(4));
        $this->appDir = $this->dir.'/app';
        $this->filesystem->mirror(__DIR__.'/Fixtures/PackagedApp', $this->appDir);
        $log = fopen('php://memory', 'w+');
        self::assertNotFalse($log);
        $this->log = $log;
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

    public function testZipShipsTheSourcesAndComposerFilesWithoutVendorNorVar(): void
    {
        $this->filesystem->dumpFile($this->appDir.'/var/cache/prod/stale.php', '');
        $this->filesystem->dumpFile($this->appDir.'/vendor/autoload.php', '');
        $this->filesystem->dumpFile($this->appDir.'/tests/ServiceTest.php', '');
        $this->filesystem->dumpFile($this->appDir.'/.env.local', 'APP_SECRET=local');
        $this->filesystem->dumpFile($this->appDir.'/.git/HEAD', '');
        $this->filesystem->dumpFile($this->appDir.'/assets/node_modules/a.js', '');
        $this->filesystem->dumpFile($this->appDir.'/terraform/terraform.tfstate', '{}');
        // A previous build, in the application like the default "function.zip": it must not end up in itself.
        $this->filesystem->dumpFile($this->appDir.'/function.zip', 'stale');

        new Packager($this->appDir, $this->log)->package($this->appDir.'/function.zip');

        $zip = new \ZipArchive();
        self::assertTrue($zip->open($this->appDir.'/function.zip'));
        $names = [];
        for ($i = 0; $i < $zip->numFiles; ++$i) {
            $names[] = (string) $zip->getNameIndex($i);
        }
        sort($names);
        self::assertSame(['.env', 'bin/console', 'composer.json', 'composer.lock', 'handler.php', 'src/Service.php'], $names);
        self::assertSame(file_get_contents($this->appDir.'/composer.lock'), $zip->getFromName('composer.lock'));
        self::assertTrue($zip->getExternalAttributesName('bin/console', $system, $attributes));
        self::assertIsInt($attributes);
        self::assertSame(0o755, ($attributes >> 16) & 0o777);
        $zip->close();
    }

    public function testWarnsAboutStaticFilesTooLargeForAFunctionResponse(): void
    {
        // Small enough as it is, but not once base64-encoded.
        $this->filesystem->dumpFile($this->appDir.'/public/video.bin', str_repeat("\xff", 5 * 1024 * 1024));
        $this->filesystem->dumpFile($this->appDir.'/public/app.css', 'body {}');
        $this->filesystem->dumpFile($this->appDir.'/src/large.bin', str_repeat("\xff", 5 * 1024 * 1024));

        $this->package();

        rewind($this->log);
        $log = (string) stream_get_contents($this->log);
        self::assertStringContainsString('WARNING: public/video.bin takes 6.7 MiB in a Function Response, over the 6 MiB Scaleway accepts', $log);
        self::assertStringNotContainsString('app.css', $log);
        self::assertStringNotContainsString('src/large.bin', $log);
        self::assertFileExists($this->dir.'/function.zip');
    }

    public function testContentHashIsComposers(): void
    {
        $composer = json_decode((string) file_get_contents(__DIR__.'/Fixtures/PackagedApp/composer.json'), true);
        $lock = json_decode((string) file_get_contents(__DIR__.'/Fixtures/PackagedApp/composer.lock'), true);
        self::assertIsArray($composer);
        self::assertIsArray($lock);

        // The fixture lock was written by Composer itself.
        self::assertSame($lock['content-hash'], Packager::contentHash($composer));
    }

    public function testApplicationWithoutCommittedLockIsRefused(): void
    {
        $this->filesystem->remove($this->appDir.'/composer.lock');

        $this->expectExceptionMessage('"composer.lock" is missing');
        $this->package();
    }

    public function testLockOutOfSyncWithComposerJsonIsRefused(): void
    {
        $this->editComposerJson(static fn (array $composer): array => ['require' => ['acme/lib' => '^2.0']] + $composer);

        $this->expectExceptionMessage('composer.lock is not up to date with composer.json');
        $this->package();
    }

    public function testPathRepositoryIsRefused(): void
    {
        // As "composer config repositories.local path .." writes it.
        $this->editComposerJson(static fn (array $composer): array => ['repositories' => ['local' => ['type' => 'path', 'url' => '..']]] + $composer);

        $this->expectExceptionMessage('composer.json declares the "path" repository ".."');
        $this->package();
    }

    #[TestWith(['packages'])]
    #[TestWith(['packages-dev'])]
    public function testPackageInstalledFromAPathIsRefused(string $section): void
    {
        $lock = json_decode((string) file_get_contents($this->appDir.'/composer.lock'), true);
        self::assertIsArray($lock);
        $packages = \is_array($lock[$section] ?? null) ? $lock[$section] : [];
        $packages[] = ['name' => 'acme/local', 'version' => 'dev-main', 'dist' => ['type' => 'path', 'url' => '../local']];
        $lock[$section] = $packages;
        $this->filesystem->dumpFile($this->appDir.'/composer.lock', (string) json_encode($lock));

        $this->expectExceptionMessage('composer.lock installs "acme/local" from a local path');
        $this->package();
    }

    public function testRefusedApplicationLeavesNoZip(): void
    {
        $this->filesystem->remove($this->appDir.'/handler.php');

        try {
            $this->package();
            self::fail('The application must be refused.');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('"handler.php" is missing', $e->getMessage());
        }
        self::assertFileDoesNotExist($this->dir.'/function.zip');
    }

    public function testPlatformRequirementsAreCheckedAgainstTheFunctionRuntimeBeforeZipping(): void
    {
        $this->requireInTheLock(['php' => '>=8.4']);

        $this->package('php84');

        self::assertFileExists($this->dir.'/function.zip');
        self::assertStringContainsString('1 satisfied, 0 unknown, 0 incompatible on php84', $this->log());
    }

    public function testIncompatiblePlatformRequirementLeavesNoZip(): void
    {
        $this->requireInTheLock(['ext-not-on-scaleway' => '*']);

        try {
            $this->package('php84');
            self::fail('The application must be refused.');
        } catch (\RuntimeException $e) {
            self::assertStringStartsWith('php84 does not meet the Platform Requirements above', $e->getMessage());
        }
        self::assertStringContainsString('INCOMPATIBLE  ext-not-on-scaleway *  required by acme/lib 1.0.0', $this->log());
        self::assertFileDoesNotExist($this->dir.'/function.zip');
    }

    public function testPlatformRequirementsAreNotCheckedWithoutAFunctionRuntime(): void
    {
        $this->requireInTheLock(['ext-not-on-scaleway' => '*']);

        $this->package();

        self::assertFileExists($this->dir.'/function.zip');
        self::assertStringNotContainsString('ext-not-on-scaleway', $this->log());
    }

    private function package(?string $functionRuntime = null): void
    {
        new Packager($this->appDir, $this->log)->package($this->dir.'/function.zip', $functionRuntime);
    }

    /**
     * @param array<string, string> $require of the locked acme/lib, which leaves the lock in sync with composer.json
     */
    private function requireInTheLock(array $require): void
    {
        $lock = json_decode((string) file_get_contents($this->appDir.'/composer.lock'), true);
        self::assertIsArray($lock);
        self::assertIsArray($lock['packages']);
        self::assertIsArray($lock['packages'][0]);
        $lock['packages'][0]['require'] = $require;
        $this->filesystem->dumpFile($this->appDir.'/composer.lock', (string) json_encode($lock));
    }

    private function log(): string
    {
        rewind($this->log);

        return (string) stream_get_contents($this->log);
    }

    /**
     * @param \Closure(array<mixed>): array<mixed> $edit
     */
    private function editComposerJson(\Closure $edit): void
    {
        $composer = json_decode((string) file_get_contents($this->appDir.'/composer.json'), true);
        self::assertIsArray($composer);
        $this->filesystem->dumpFile($this->appDir.'/composer.json', (string) json_encode($edit($composer)));
    }
}

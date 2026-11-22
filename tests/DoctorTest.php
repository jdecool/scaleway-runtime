<?php

declare(strict_types=1);

namespace JDecool\ScalewayRuntime\Tests;

use JDecool\ScalewayRuntime\Doctor;
use JDecool\ScalewayRuntime\FunctionRuntimeCatalogue;
use JDecool\ScalewayRuntime\Packager;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

final class DoctorTest extends TestCase
{
    private const string SCOPE = 'This covers declared Platform Requirements only, not whether the application runs.';

    private Filesystem $filesystem;
    private string $appDir;
    private FunctionRuntimeCatalogue $catalogue;

    protected function setUp(): void
    {
        $this->filesystem = new Filesystem();
        $this->appDir = sys_get_temp_dir().'/scaleway-runtime-'.bin2hex(random_bytes(4));
        $this->catalogue = new FunctionRuntimeCatalogue([
            'php84' => ['measuredAt' => '2026-11-22T11:47:13Z', 'php' => '8.4.28', 'extensions' => ['ext-ctype' => '8.4.28', 'ext-mysqlnd' => '0', 'ext-redis' => '6.3.0']],
            'php85' => ['measuredAt' => '2026-11-21T09:00:00Z', 'php' => '8.5.13', 'extensions' => ['ext-ctype' => '8.5.13', 'ext-redis' => '6.3.0', 'ext-sodium' => '8.5.13']],
        ]);
    }

    protected function tearDown(): void
    {
        $this->filesystem->remove($this->appDir);
    }

    public function testSatisfiedRequirementsOnlyPrintTheSummary(): void
    {
        $this->application(['php' => '>=8.4', 'ext-ctype' => '*'], [self::package('acme/cache', ['php' => '^8.1', 'ext-Redis' => '^6.0'])]);

        self::assertSame([true, "4 satisfied, 0 unknown, 0 incompatible on php84 (measured 2026-11-22)\n".self::SCOPE."\n"], $this->check('php84'));
    }

    public function testIncompatibleRequirementsAreReportedWithTheirDeclarerAndASuggestion(): void
    {
        $this->application(['php' => '^8.5'], [
            self::package('acme/crypto', ['ext-sodium' => '*']),
            self::package('acme/queue', ['ext-redis' => '^7.0']),
        ]);

        self::assertSame([false, <<<'TXT'
            INCOMPATIBLE  php ^8.5        required by the application     php84: 8.4.28 (measured 2026-11-22)
                          php85 meets it: deploy on php85, or use Serverless Containers for custom extensions or versions. If Scaleway has upgraded PHP since the measurement, update jdecool/scaleway-runtime or re-run its probe.
            INCOMPATIBLE  ext-sodium *    required by acme/crypto v1.0.0  php84: not loaded (measured 2026-11-22)
                          php85 meets it: deploy on php85, or use Serverless Containers for custom extensions or versions.
            INCOMPATIBLE  ext-redis ^7.0  required by acme/queue v1.0.0   php84: 6.3.0 (measured 2026-11-22)
                          No measured Function Runtime meets it: use Serverless Containers for custom extensions or versions.

            0 satisfied, 0 unknown, 3 incompatible on php84 (measured 2026-11-22)
            This covers declared Platform Requirements only, not whether the application runs.

            TXT], $this->check('php84'));
    }

    public function testMeasuredPhpPatchIsAuthoritative(): void
    {
        $this->application(['php' => '>=8.4.29']);

        [$compatible, $report] = $this->check('php84');

        self::assertFalse($compatible);
        self::assertStringContainsString('INCOMPATIBLE  php >=8.4.29  required by the application  php84: 8.4.28 (measured 2026-11-22)', $report);
    }

    public function testComposersFallbackVersionOfAnExtensionIsCompared(): void
    {
        $this->application(['ext-mysqlnd' => '>=8.0']);

        self::assertStringContainsString('php84: 0 (measured 2026-11-22)', $this->check('php84')[1]);
    }

    public function testDevelopmentRequirementsAreNotChecked(): void
    {
        $this->application(['php' => '>=8.4'], [], [
            'packages-dev' => [self::package('acme/profiler', ['ext-xdebug' => '*'])],
            'platform-dev' => ['ext-pcov' => '*'],
        ]);

        self::assertSame([true, "1 satisfied, 0 unknown, 0 incompatible on php84 (measured 2026-11-22)\n".self::SCOPE."\n"], $this->check('php84'));
    }

    public function testProvidedAndReplacedExtensionsAreSatisfied(): void
    {
        $this->application(
            [],
            [
                self::package('acme/crypto', ['ext-sodium' => '*', 'ext-intl' => '^1.0', 'ext-gmp' => '^2.0']),
                self::package('acme/polyfill-sodium', [], ['provide' => ['ext-sodium' => '*']]),
                self::package('acme/polyfill-intl', [], ['replace' => ['ext-intl' => 'self.version']]),
            ],
            [],
            ['provide' => ['ext-gmp' => '2.1.0']],
        );

        self::assertSame([true, "3 satisfied, 0 unknown, 0 incompatible on php84 (measured 2026-11-22)\n".self::SCOPE."\n"], $this->check('php84'));
    }

    public function testProviderNotMatchingTheConstraintDoesNotSatisfyIt(): void
    {
        $this->application([], [
            self::package('acme/crypto', ['ext-sodium' => '^2.0']),
            self::package('acme/polyfill-sodium', [], ['provide' => ['ext-sodium' => '1.0.0']]),
        ]);

        self::assertFalse($this->check('php84')[0]);
    }

    public function testUnmeasuredPlatformPackagesAreUnknownWithoutFailing(): void
    {
        $this->application(['lib-icu' => '>=72', 'php-64bit' => '*']);

        self::assertSame([true, <<<'TXT'
            UNKNOWN  lib-icu >=72  required by the application  not in the Function Runtime Catalogue
            UNKNOWN  php-64bit *   required by the application  not in the Function Runtime Catalogue

            0 satisfied, 2 unknown, 0 incompatible on php84 (measured 2026-11-22)
            This covers declared Platform Requirements only, not whether the application runs.

            TXT], $this->check('php84'));
    }

    public function testComposerApisAndPackagesAreNotPlatformRequirements(): void
    {
        $this->application(['composer-runtime-api' => '^2.2', 'symfony/runtime' => '^8.0'], [self::package('symfony/runtime', ['composer-plugin-api' => '^1.0|^2.0', 'composer' => '^2.0', 'php' => '>=8.2'])]);

        self::assertSame([true, "1 satisfied, 0 unknown, 0 incompatible on php84 (measured 2026-11-22)\n".self::SCOPE."\n"], $this->check('php84'));
    }

    public function testUnmeasuredFunctionRuntimeIsRefused(): void
    {
        $this->application();

        $this->expectExceptionMessage('"php83" is not a measured Function Runtime: use php84 or php85.');

        $this->check('php83');
    }

    #[TestWith(['composer.json'])]
    #[TestWith(['composer.lock'])]
    public function testMissingComposerFileIsRefused(string $file): void
    {
        $this->application();
        $this->filesystem->remove($this->appDir.'/'.$file);

        $this->expectExceptionMessage(\sprintf('"%s" is missing in', $file));

        $this->check('php84');
    }

    public function testStaleLockIsRefused(): void
    {
        $this->application(['php' => '>=8.4']);
        $this->filesystem->dumpFile($this->appDir.'/composer.json', json_encode(['require' => ['php' => '>=8.5']], \JSON_THROW_ON_ERROR));

        $this->expectExceptionMessage('composer.lock is not up to date with composer.json');

        $this->check('php84');
    }

    public function testInvalidConstraintIsRefused(): void
    {
        $this->application([], [self::package('acme/broken', ['php' => 'not a constraint'])]);

        $this->expectExceptionMessage('The invalid constraint "not a constraint" is declared by acme/broken v1.0.0');

        $this->check('php84');
    }

    public function testInvalidConstraintOfTheApplicationIsRefused(): void
    {
        $this->application([], [], [], ['provide' => ['ext-gmp' => 'not a constraint']]);

        $this->expectExceptionMessage('The invalid constraint "not a constraint" is declared by the application');

        $this->check('php84');
    }

    #[TestWith(['composer.json'])]
    #[TestWith(['composer.lock'])]
    public function testInvalidComposerFileIsRefused(string $file): void
    {
        $this->application();
        $this->filesystem->dumpFile($this->appDir.'/'.$file, '{');

        $this->expectExceptionMessage($file.' must hold a JSON object.');

        $this->check('php84');
    }

    public function testCommandLine(): void
    {
        self::assertSame(0, self::runDoctor(['--help'])[0]);
        // This package's own lock, as the root package of the checkout: the report goes to stderr only.
        [$exitCode, $stderr, $stdout] = self::runDoctor(['--runtime=php84']);
        self::assertSame([0, ''], [$exitCode, $stdout]);
        self::assertStringContainsString('incompatible on php84', $stderr);
        [$exitCode, $stderr] = self::runDoctor([]);
        self::assertSame(1, $exitCode);
        self::assertStringStartsWith('Usage: scaleway-doctor --runtime=<function-runtime>', $stderr);
        self::assertSame(1, self::runDoctor(['--runtime=php83'])[0]);
    }

    /**
     * @param array<string, string>      $platform the root Platform Requirements, recorded by Composer in the lock
     * @param list<array<string, mixed>> $packages
     * @param array<string, mixed>       $lock
     * @param array<string, mixed>       $composer
     */
    private function application(array $platform = [], array $packages = [], array $lock = [], array $composer = []): void
    {
        $composer += ['require' => $platform];
        $this->filesystem->dumpFile($this->appDir.'/composer.json', json_encode($composer, \JSON_THROW_ON_ERROR));
        $this->filesystem->dumpFile($this->appDir.'/composer.lock', json_encode($lock + [
            'content-hash' => Packager::contentHash($composer),
            'packages' => $packages,
            'packages-dev' => [],
            // Composer writes an empty JSON array, not an object.
            'platform' => [] === $platform ? [] : $platform,
            'platform-dev' => [],
        ], \JSON_THROW_ON_ERROR));
    }

    /**
     * @param array<string, string> $require
     * @param array<string, mixed>  $links
     *
     * @return array<string, mixed>
     */
    private static function package(string $name, array $require, array $links = []): array
    {
        return ['name' => $name, 'version' => 'v1.0.0', 'require' => $require] + $links;
    }

    /**
     * @return array{bool, string}
     */
    private function check(string $functionRuntime): array
    {
        $log = fopen('php://memory', 'w+');
        self::assertNotFalse($log);
        $compatible = new Doctor($this->catalogue, $log)->check($this->appDir, $functionRuntime);
        rewind($log);

        return [$compatible, (string) stream_get_contents($log)];
    }

    /**
     * @param list<string> $arguments
     *
     * @return array{int, string, string} exit code, stderr and stdout
     */
    private static function runDoctor(array $arguments): array
    {
        $process = proc_open([\PHP_BINARY, __DIR__.'/../bin/scaleway-doctor', ...$arguments], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($process);
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);

        return [proc_close($process), $stderr, $stdout];
    }
}

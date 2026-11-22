<?php

declare(strict_types=1);

namespace JDecool\ScalewayRuntime\Tests;

use JDecool\ScalewayRuntime\FunctionRuntimeCatalogue;
use PHPUnit\Framework\TestCase;

final class FunctionRuntimeCatalogueTest extends TestCase
{
    public function testCommittedCatalogueIsGeneratedFromTheProbeOutputs(): void
    {
        $files = glob(__DIR__.'/Fixtures/FunctionRuntimes/*.json') ?: [];
        self::assertNotEmpty($files);

        self::assertSame(
            FunctionRuntimeCatalogue::generate(array_map(static fn (string $file): string => (string) file_get_contents($file), $files)),
            file_get_contents(FunctionRuntimeCatalogue::FILE),
            'resources/function-runtimes.php is out of date: run "php probe/catalogue.php".',
        );
    }

    public function testMeasuredFunctionRuntimesAreSupported(): void
    {
        $catalogue = FunctionRuntimeCatalogue::load();

        self::assertSame(['php84', 'php85'], array_keys($catalogue->functionRuntimes));
        self::assertMatchesRegularExpression('/^8\.4\.\d+$/', $catalogue->get('php84')['php']);
    }

    public function testNamesAndVersionsAreComposers(): void
    {
        $catalogue = self::generated([
            'measuredAt' => '2026-11-22T11:47:13Z',
            'phpVersion' => '8.4.28-1+deb12',
            'extensions' => [
                'Core' => '8.4.28',
                'standard' => '8.4.28',
                'Zend OPcache' => '8.4.28',
                'PDO_OCI' => '1.2.0',
                'mysqlnd' => 'mysqlnd 8.4.28',
                'oddity' => '1.2.3 build 4',
                'unversioned' => false,
                'igbinary' => '3.2.17RC1',
            ],
        ]);

        self::assertSame([
            'php84' => [
                'measuredAt' => '2026-11-22T11:47:13Z',
                'php' => '8.4.28',
                'extensions' => [
                    'ext-igbinary' => '3.2.17RC1',
                    'ext-mysqlnd' => '0',
                    'ext-oddity' => '1.2.3',
                    'ext-pdo_oci' => '1.2.0',
                    'ext-unversioned' => '0',
                    'ext-zend-opcache' => '8.4.28',
                ],
            ],
        ], $catalogue->functionRuntimes);
    }

    public function testParseablePhpVersionIsKeptWhole(): void
    {
        $catalogue = self::generated(['measuredAt' => '2026-11-22T11:47:13Z', 'phpVersion' => '8.5.0-RC1', 'extensions' => []]);

        self::assertSame('8.5.0-RC1', $catalogue->get('php85')['php']);
    }

    public function testTwoMeasurementsOfTheSameFunctionRuntimeAreRefused(): void
    {
        $measurement = json_encode(['measuredAt' => '2026-11-22T11:47:13Z', 'phpVersion' => '8.4.28', 'extensions' => []], \JSON_THROW_ON_ERROR);

        $this->expectExceptionMessage('Two probe outputs measure php84: keep one.');

        FunctionRuntimeCatalogue::generate([$measurement, str_replace('8.4.28', '8.4.29', $measurement)]);
    }

    public function testIncompleteMeasurementIsRefused(): void
    {
        $this->expectExceptionMessage('A probe output must hold "measuredAt", "phpVersion" and "extensions".');

        FunctionRuntimeCatalogue::generate(['{"phpVersion": "8.4.28"}']);
    }

    public function testUnmeasuredFunctionRuntimeIsRefused(): void
    {
        $this->expectExceptionMessage('"php83" is not a measured Function Runtime: use php84 or php85.');

        FunctionRuntimeCatalogue::load()->get('php83');
    }

    public function testChangesListWhatANewMeasurementChanged(): void
    {
        $old = new FunctionRuntimeCatalogue([
            'php83' => ['measuredAt' => '2026-11-14T10:00:00Z', 'php' => '8.3.30', 'extensions' => []],
            'php84' => ['measuredAt' => '2026-11-14T10:00:00Z', 'php' => '8.4.27', 'extensions' => ['ext-amqp' => '2.2.0', 'ext-ctype' => '8.4.27', 'ext-redis' => '6.3.0']],
            'php85' => ['measuredAt' => '2026-11-14T10:00:00Z', 'php' => '8.5.13', 'extensions' => ['ext-redis' => '6.3.0']],
        ]);
        $new = new FunctionRuntimeCatalogue([
            'php84' => ['measuredAt' => '2026-11-29T10:00:00Z', 'php' => '8.4.29', 'extensions' => ['ext-ctype' => '8.4.29', 'ext-foo' => '1.2.0', 'ext-redis' => '6.3.0']],
            'php85' => ['measuredAt' => '2026-11-29T10:00:00Z', 'php' => '8.5.13', 'extensions' => ['ext-redis' => '6.3.0']],
            'php86' => ['measuredAt' => '2026-11-29T10:00:00Z', 'php' => '8.6.0', 'extensions' => ['ext-redis' => '6.3.0']],
        ]);

        self::assertSame([
            'php83: no longer measured',
            'php84: PHP 8.4.27 -> 8.4.29',
            'php84: ext-ctype 8.4.27 -> 8.4.29',
            'php84: + ext-foo 1.2.0',
            'php84: - ext-amqp',
            'php84: measured 2026-11-14T10:00:00Z -> 2026-11-29T10:00:00Z',
            'php86: new Function Runtime, PHP 8.6.0 with 1 extensions',
        ], $new->changes($old));
    }

    public function testNewMeasurementDatesAloneAreNoChange(): void
    {
        $catalogue = FunctionRuntimeCatalogue::load();
        $remeasured = new FunctionRuntimeCatalogue(array_map(static fn (array $functionRuntime): array => ['measuredAt' => '2099-01-01T00:00:00Z'] + $functionRuntime, $catalogue->functionRuntimes));

        self::assertSame([], $remeasured->changes($catalogue));
    }

    /**
     * @param array<string, mixed> $measurement
     */
    private static function generated(array $measurement): FunctionRuntimeCatalogue
    {
        $file = tempnam(sys_get_temp_dir(), 'catalogue');
        self::assertNotFalse($file);
        try {
            file_put_contents($file, FunctionRuntimeCatalogue::generate([json_encode($measurement, \JSON_THROW_ON_ERROR)]));
            /** @var array<string, array{measuredAt: string, php: string, extensions: array<string, string>}> $functionRuntimes */
            $functionRuntimes = require $file;

            return new FunctionRuntimeCatalogue($functionRuntimes);
        } finally {
            unlink($file);
        }
    }
}

<?php

declare(strict_types=1);

/*
 * Regenerates resources/function-runtimes.php from the probe outputs in tests/Fixtures/FunctionRuntimes,
 * saved with the probe's "runtime" mode: see docs/probe-results.md#function-runtime-catalogue.
 */

use JDecool\ScalewayRuntime\FunctionRuntimeCatalogue;

require __DIR__.'/../vendor/autoload.php';

$files = glob(__DIR__.'/../tests/Fixtures/FunctionRuntimes/*.json') ?: [];
file_put_contents(FunctionRuntimeCatalogue::FILE, FunctionRuntimeCatalogue::generate(array_map(static fn (string $file): string => (string) file_get_contents($file), $files)));

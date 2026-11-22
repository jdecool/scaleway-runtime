<?php

declare(strict_types=1);

/*
 * Regenerates resources/function-runtimes.php from the probe outputs in tests/Fixtures/FunctionRuntimes,
 * saved with the probe's "runtime" mode, and prints what changed: see docs/probe-results.md#function-runtime-catalogue.
 */

use JDecool\ScalewayRuntime\FunctionRuntimeCatalogue;

require __DIR__.'/../vendor/autoload.php';

$old = FunctionRuntimeCatalogue::load();
$files = glob(__DIR__.'/../tests/Fixtures/FunctionRuntimes/*.json') ?: [];
file_put_contents(FunctionRuntimeCatalogue::FILE, FunctionRuntimeCatalogue::generate(array_map(static fn (string $file): string => (string) file_get_contents($file), $files)));

// Prints what changed, nothing when only the measurement dates did: the freshness workflow opens a pull request on output.
foreach (FunctionRuntimeCatalogue::load()->changes($old) as $change) {
    echo $change, \PHP_EOL;
}

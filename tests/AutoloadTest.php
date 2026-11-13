<?php

declare(strict_types=1);

namespace JDecool\ScalewayRuntime\Tests;

use Composer\Autoload\ClassLoader;
use PHPUnit\Framework\TestCase;

// Placeholder until Phase 2 adds real classes: guards the PSR-4 mapping the runtime code will rely on.
final class AutoloadTest extends TestCase
{
    public function testPackageNamespaceMapsToSrc(): void
    {
        $paths = [];
        foreach (ClassLoader::getRegisteredLoaders() as $loader) {
            $paths = [...$paths, ...$loader->getPrefixesPsr4()['JDecool\\ScalewayRuntime\\'] ?? []];
        }

        self::assertSame([realpath(__DIR__.'/../src')], array_map(realpath(...), $paths));
    }
}

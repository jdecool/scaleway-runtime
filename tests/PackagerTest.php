<?php

declare(strict_types=1);

namespace JDecool\ScalewayRuntime\Tests;

use JDecool\ScalewayRuntime\Packager;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;

final class PackagerTest extends TestCase
{
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
}

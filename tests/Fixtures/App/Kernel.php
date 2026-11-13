<?php

declare(strict_types=1);

namespace JDecool\ScalewayRuntime\Tests\Fixtures\App;

use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

final class Kernel extends BaseKernel
{
    use MicroKernelTrait;

    public static int $instances = 0;

    public function __construct(string $environment, bool $debug)
    {
        ++self::$instances;
        parent::__construct($environment, $debug);
    }

    // The fixture is copied out of this repository, whose composer.json the default lookup would find.
    public function getProjectDir(): string
    {
        $projectDir = $_SERVER['APP_PROJECT_DIR'] ?? null;
        \assert(\is_string($projectDir));

        return $projectDir;
    }

    public function state(Request $request): JsonResponse
    {
        $counter = $this->getContainer()->get(Counter::class);
        \assert($counter instanceof Counter);

        return new JsonResponse([
            'count' => $counter->increment(),
            'kernelInstances' => self::$instances,
            'pid' => getmypid(),
            'cacheDir' => $this->getCacheDir(),
            'buildDir' => $this->getBuildDir(),
            'secure' => $request->isSecure(),
            'clientIp' => $request->getClientIp(),
            'query' => $request->query->all(),
        ]);
    }

    public function upload(Request $request): JsonResponse
    {
        $file = $request->files->get('doc');
        \assert($file instanceof UploadedFile);

        return new JsonResponse([
            'title' => $request->request->get('title'),
            'name' => $file->getClientOriginalName(),
            'content' => $file->getContent(),
        ]);
    }

    private function configureContainer(ContainerConfigurator $container): void
    {
        $container->extension('framework', ['secret' => 'fixture', 'http_method_override' => false]);
        $container->services()
            ->set(Counter::class)
            ->public()
            ->tag('kernel.reset', ['method' => 'reset'])
            ->set(CountCommand::class)
            ->autowire()
            ->autoconfigure();
    }

    private function configureRoutes(RoutingConfigurator $routes): void
    {
        $routes->add('state', '/state')->controller('kernel::state');
        $routes->add('upload', '/upload')->controller('kernel::upload');
    }
}

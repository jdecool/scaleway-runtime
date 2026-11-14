<?php

declare(strict_types=1);

use JDecool\ScalewayRuntime\Tests\Fixtures\RecordingKernel;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\HttpKernel\Kernel;

// A bin/console whose Kernel fails to boot while RecordingKernel::$failingBuilds is positive.
return static fn (): Application => new Application(new class('test', false) extends Kernel {
    public function registerBundles(): iterable
    {
        return [];
    }

    public function registerContainerConfiguration(LoaderInterface $loader): void
    {
    }

    // Skips the container compilation, which would write a cache dir into the repository.
    public function boot(): void
    {
        if (RecordingKernel::$failingBuilds > 0) {
            --RecordingKernel::$failingBuilds;

            throw new RuntimeException('Boot failed on purpose.');
        }
        $this->container ??= new Container();
        $this->container->set('event_dispatcher', new EventDispatcher());
        $this->booted = true;
    }
});

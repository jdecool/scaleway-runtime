<?php

declare(strict_types=1);

use JDecool\ScalewayRuntime\Tests\Fixtures\App\Kernel;
use Symfony\Bundle\FrameworkBundle\Console\Application;

require_once dirname(__DIR__).'/vendor/autoload_runtime.php';

// The bin/console of a Symfony application, as a Front Controller.
return static function (array $context): Application {
    assert(is_string($context['APP_ENV']));

    return new Application(new Kernel($context['APP_ENV'], (bool) $context['APP_DEBUG']));
};

<?php

declare(strict_types=1);

use JDecool\ScalewayRuntime\Tests\Fixtures\EchoRequestHandler;

require_once dirname(__DIR__).'/vendor/autoload_runtime.php';

return static fn (): EchoRequestHandler => new EchoRequestHandler();

<?php

declare(strict_types=1);

use JDecool\ScalewayRuntime\Tests\Fixtures\EchoRequestHandler;

return static fn (): EchoRequestHandler => new EchoRequestHandler();

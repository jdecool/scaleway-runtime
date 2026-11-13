<?php

declare(strict_types=1);

use JDecool\ScalewayRuntime\Tests\Fixtures\RecordingKernel;

return static fn (): RecordingKernel => RecordingKernel::build();

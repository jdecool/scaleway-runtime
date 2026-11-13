<?php

declare(strict_types=1);

namespace JDecool\ScalewayRuntime\Tests\Fixtures\App;

use Symfony\Contracts\Service\ResetInterface;

/**
 * Request-scoped state that leaks across Invocations unless the Kernel resets its services.
 */
final class Counter implements ResetInterface
{
    private int $count = 0;

    public function increment(): int
    {
        return ++$this->count;
    }

    public function reset(): void
    {
        $this->count = 0;
    }
}

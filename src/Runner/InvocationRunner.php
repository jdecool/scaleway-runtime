<?php

declare(strict_types=1);

namespace JDecool\ScalewayRuntime\Runner;

use JDecool\ScalewayRuntime\HttpConverter;

/**
 * Turns one Event into one Function Response for an Application kept across Invocations.
 *
 * Symfony's RunnerInterface::run(): int has no room for an Event nor a Function Response, hence this contract.
 *
 * @phpstan-import-type FunctionResponse from HttpConverter
 */
interface InvocationRunner
{
    /**
     * @param array<mixed> $event
     *
     * @return FunctionResponse
     */
    public function invoke(array $event, mixed $context): array;
}

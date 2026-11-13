<?php

require __DIR__.'/vendor/autoload.php';

function handle(array $event, mixed $context): array
{
    return JDecool\ScalewayRuntime\Handler::handle($event, $context);
}

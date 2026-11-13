<?php

declare(strict_types=1);

// Mirrors the first check of the vendor/autoload_runtime.php Symfony generates: the Handler already
// loaded vendor/autoload.php, so an unchanged Front Controller must return here and go no further.
if (true === (require_once __DIR__.'/autoload.php')) {
    return;
}

throw new LogicException('The Front Controller must not boot the Symfony Runtime on Scaleway.');

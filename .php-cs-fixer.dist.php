<?php

declare(strict_types=1);

$finder = (new PhpCsFixer\Finder())
    ->in([__DIR__.'/src', __DIR__.'/tests', __DIR__.'/resources'])
    // The stub users commit stays as short as possible.
    ->notPath('handler.php')
    ->append([__FILE__, __DIR__.'/bin/scaleway-init', __DIR__.'/bin/scaleway-package', __DIR__.'/bin/scaleway-serve']);

return (new PhpCsFixer\Config())
    ->setRiskyAllowed(true)
    ->setRules([
        '@PER-CS' => true,
        '@Symfony' => true,
        '@Symfony:risky' => true,
        'declare_strict_types' => true,
    ])
    ->setFinder($finder);

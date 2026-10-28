<?php

$finder = (new PhpCsFixer\Finder())
    ->in([__DIR__.'/src', __DIR__.'/tests'])
    ->append([__FILE__]);

return (new PhpCsFixer\Config())
    ->setRules([
        '@Symfony' => true,
        // Les commentaires du code sont en français : la ponctuation française reste telle quelle.
        'phpdoc_summary' => false,
    ])
    ->setRiskyAllowed(false)
    ->setFinder($finder)
    ->setCacheFile(__DIR__.'/var/.php-cs-fixer.cache');

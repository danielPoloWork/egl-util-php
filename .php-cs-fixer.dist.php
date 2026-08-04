<?php

declare(strict_types=1);

use PhpCsFixer\Config;
use PhpCsFixer\Finder;

$finder = Finder::create()
    ->in([
        __DIR__ . '/src/main/php/d4np/utils',
        __DIR__ . '/src/test/php/d4np/utils',
    ]);

return (new Config())
    ->setRiskyAllowed(true)
    ->setRules([
        '@PSR12' => true,
        '@PSR12:risky' => true,
        'declare_strict_types' => true,
        'strict_comparison' => true,
        'strict_param' => true,
        'no_unused_imports' => true,
        'ordered_imports' => ['sort_algorithm' => 'alpha'],
        'single_quote' => true,
        'trailing_comma_in_multiline' => true,
        // allow_mixed is deliberate. The rule's default strips `@param mixed $x`, but on a
        // parameter with no native type that annotation is the ONLY thing giving PHPStan (at
        // max level) a type — removing it turns a clean file into a `missingType.parameter`
        // error. The two tools disagree by default; this is where the disagreement is settled,
        // rather than by suppressing whichever one complains second.
        'no_superfluous_phpdoc_tags' => ['allow_mixed' => true],
    ])
    ->setFinder($finder);

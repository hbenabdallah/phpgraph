<?php

declare(strict_types=1);

$finder = (new PhpCsFixer\Finder())
    ->in([__DIR__ . '/src', __DIR__ . '/tests', __DIR__ . '/tools'])
    ->exclude('Fixtures')
    ->notPath('Vendor/internal-classes.php')
    ->append([__DIR__ . '/bin/phpgraph']);

return (new PhpCsFixer\Config())
    ->setRiskyAllowed(true)
    ->setRules([
        '@PER-CS2.0' => true,
        'single_line_empty_body' => false,
        'declare_strict_types' => true,
        'function_declaration' => ['closure_fn_spacing' => 'one'],
        'no_unused_imports' => true,
        'ordered_imports' => true,
    ])
    ->setFinder($finder);

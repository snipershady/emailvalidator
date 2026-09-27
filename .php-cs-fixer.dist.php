<?php

declare(strict_types=1);

$finder = (new PhpCsFixer\Finder())
    ->in([__DIR__ . '/src', __DIR__ . '/tests'])
;

return (new PhpCsFixer\Config())
    ->setRules([
        '@Symfony' => true,
        '@Symfony:risky' => true,

        // --- Style overrides that differ from the Symfony defaults ------------
        // @Symfony:risky would prefix global functions/constants with a leading
        // "\" (\is_array(), \PHP_INT_MAX, ...). The perf gain is negligible for
        // this library and it clashes with the existing unprefixed style, so
        // global symbols are left bare everywhere.
        'native_function_invocation' => false,
        'native_constant_invocation' => false,
        'array_syntax' => ['syntax' => 'short'],
        'list_syntax' => ['syntax' => 'short'],
        // Symfony forces no spaces around "."; this project wants "a . b".
        'concat_space' => ['spacing' => 'one'],
        'no_extra_blank_lines' => ['tokens' => ['extra', 'use', 'return']],

        // --- Strict-types enforcement --------------------------------------
        // Guarantee the declare() is present (until now it was added by hand).
        'declare_strict_types' => true,
        // == / != are forbidden: always ===, !== and the strict flag.
        'strict_comparison' => true,
        'strict_param' => true,

        // NB: class-name imports (turning `\Foo\Bar` FQCN into a `use`) are left
        // to Rector's ->withImportNames(); php-cs-fixer's global_namespace_import
        // is intentionally NOT enabled because on this codebase it also pulls in
        // walls of `use const FILTER_*` / `use const INPUT_*` and rewrites
        // `#[\Override]`, which is noise rather than clarity.

        // --- PHPUnit ----------------------------------------------------------
        'php_unit_method_casing' => ['case' => 'camel_case'],
        'php_unit_fqcn_annotation' => true,
        'php_unit_set_up_tear_down_visibility' => true,
        'php_unit_test_annotation' => ['style' => 'prefix'],
        'php_unit_construct' => true,
        'php_unit_dedicate_assert' => ['target' => 'newest'],
        'php_unit_dedicate_assert_internal_type' => ['target' => 'newest'],
        'php_unit_expectation' => ['target' => 'newest'],
        'php_unit_mock' => ['target' => 'newest'],
        'php_unit_no_expectation_annotation' => ['target' => 'newest'],
    ])
    ->setRiskyAllowed(true)
    ->setFinder($finder)
;


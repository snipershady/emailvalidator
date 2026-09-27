<?php

declare(strict_types=1);

use Rector\CodeQuality\Rector\Attribute\ExplicitAttributeNamedArgsRector;
use Rector\Config\RectorConfig;
use Rector\Set\ValueObject\LevelSetList;

return RectorConfig::configure()
    ->withPaths([
        __DIR__ . '/src',
        __DIR__ . '/tests',
    ])
    ->withCache(__DIR__ . '/.rector.cache')
    ->withImportNames(
        importShortClasses: false,
        removeUnusedImports: true,
    )
    ->withPreparedSets(
        deadCode: true,
        codeQuality: true,
        codingStyle: true,
        typeDeclarations: true,
        typeDeclarationDocblocks: true,
        privatization: true,
        naming: true,
        namedArgs: true,
        instanceOf: true,
        earlyReturn: true,
        phpunitCodeQuality: true,
        phpunitNarrowAsserts: true,
        phpunitMockToStub: true,
    )
    ->withAttributesSets(phpunit: true)
    ->withComposerBased(phpunit: true)
    ->withSets([
        LevelSetList::UP_TO_PHP_83,
    ])
    // Gli attributi PHPUnit (DataProvider, Group, ...) sono dichiarati con
    // #[NoNamedArguments]: aggiungere argomenti nominati alle loro chiamate
    // (come farebbe questa regola) produce codice che PHPStan rifiuta con
    // "invoked with named argument ..., but it's not allowed because of
    // @no-named-arguments".
    ->withSkip([
        ExplicitAttributeNamedArgsRector::class,
    ])
;

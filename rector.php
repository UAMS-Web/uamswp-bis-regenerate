<?php

/**
 * Rector, gating profile, from the code-quality skill's template
 * (UAMS-Web/uams-claude-skills#10). `composer test:refactor` is the gate.
 */

declare(strict_types=1);

use Rector\CodeQuality\Rector\ClassMethod\LocallyCalledStaticMethodToNonStaticRector;
use Rector\Config\RectorConfig;
use Rector\DeadCode\Rector\Property\RemoveUselessVarTagRector;
use Rector\Php81\Rector\Array_\ArrayToFirstClassCallableRector;
use Rector\Php81\Rector\Property\ReadOnlyPropertyRector;
use Rector\Php82\Rector\Class_\ReadOnlyClassRector;
use Rector\TypeDeclaration\Rector\ClassMethod\AddParamFromDimFetchKeyUseRector;
use Rector\TypeDeclaration\Rector\ClassMethod\StrictArrayParamDimFetchRector;
use Rector\ValueObject\PhpVersion;

return RectorConfig::configure()
    ->withPaths([
        __DIR__.'/includes',
        __DIR__.'/tests',
    ])
    ->withRootFiles()
    ->withSkip([
        __DIR__.'/tests/Integration/vendor',
        // WordPress matches hook callbacks by identity: a first-class callable silently
        // breaks remove_action() (D-016 in aether-wordpress-migration-api).
        ArrayToFirstClassCallableRector::class,
        // Sweep-only rules in the shared template; never part of the gate.
        ReadOnlyClassRector::class,
        ReadOnlyPropertyRector::class,
        LocallyCalledStaticMethodToNonStaticRector::class,
        RemoveUselessVarTagRector::class,
        StrictArrayParamDimFetchRector::class,
        AddParamFromDimFetchKeyUseRector::class,
    ])
    ->withPreparedSets(
        deadCode: true,
        codeQuality: true,
        codingStyle: true,
        typeDeclarations: true,
        privatization: true,
        earlyReturn: true,
    )
    ->withPhpVersion(PhpVersion::PHP_82)
    ->withPhpSets()
    ->withImportNames(importShortClasses: false, removeUnusedImports: false);

<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;
use Rector\CodeQuality\Rector\Identical\FlipTypeControlToUseExclusiveTypeRector;
use Rector\Php81\Rector\Property\ReadOnlyPropertyRector;
use Rector\Strict\Rector\Empty_\DisallowedEmptyRuleFixerRector;
use Rector\Set\ValueObject\LevelSetList;
use Rector\Set\ValueObject\SetList;

return RectorConfig::configure()
    // Low-risk baseline: modern code first, legacy and plugins later.
    ->withPaths([
        __DIR__ . '/src',
        __DIR__ . '/tests',
    ])
    ->withSkip([
        __DIR__ . '/src/Core',
        __DIR__ . '/src/Core/Base',
        __DIR__ . '/src/DependencyInjection',
        __DIR__ . '/src/Dinamic',
        ReadOnlyPropertyRector::class,
        FlipTypeControlToUseExclusiveTypeRector::class,
        DisallowedEmptyRuleFixerRector::class,
    ])
    ->withSets([
        LevelSetList::UP_TO_PHP_83,
        SetList::CODE_QUALITY,
        SetList::DEAD_CODE,
        SetList::EARLY_RETURN,
    ])
    ->withImportNames(removeUnusedImports: true)
    ->withParallel(timeoutSeconds: 120, maxNumberOfProcess: 8)
    ->withCache(__DIR__ . '/tmp/rector');
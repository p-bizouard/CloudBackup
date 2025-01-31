<?php

declare(strict_types=1);

use Rector\CodeQuality\Rector\Identical\FlipTypeControlToUseExclusiveTypeRector;
use Rector\Config\RectorConfig;
use Rector\DeadCode\Rector\Cast\RecastingRemovalRector;
use Rector\DeadCode\Rector\Node\RemoveNonExistingVarAnnotationRector;
use Rector\Doctrine\Set\DoctrineSetList;
use Rector\Naming\Rector\Assign\RenameVariableToMatchMethodCallReturnTypeRector;
use Rector\Naming\Rector\Class_\RenamePropertyToMatchTypeRector;
use Rector\Naming\Rector\ClassMethod\RenameParamToMatchTypeRector;
use Rector\Php74\Rector\Closure\ClosureToArrowFunctionRector;
use Rector\Set\ValueObject\LevelSetList;
use Rector\Set\ValueObject\SetList;
use Rector\Symfony\CodeQuality\Rector\ClassMethod\ActionSuffixRemoverRector;
use Rector\Symfony\Set\SymfonySetList;

return static function (RectorConfig $rectorConfig): void {
    $rectorConfig->importNames();

    $rectorConfig->paths([
        __DIR__.'/src',
        //        __DIR__.'/tests',
    ]);

    $rectorConfig->autoloadPaths([
        __DIR__.'/vendor/autoload.php',
    ]);

    $rectorConfig->sets([
        SetList::DEAD_CODE,
        LevelSetList::UP_TO_PHP_84,
        SetList::CODE_QUALITY,
        SetList::NAMING,
        SymfonySetList::SYMFONY_72,
        SymfonySetList::SYMFONY_CODE_QUALITY,
        SymfonySetList::SYMFONY_CONSTRUCTOR_INJECTION,
        SymfonySetList::ANNOTATIONS_TO_ATTRIBUTES,
        DoctrineSetList::DOCTRINE_CODE_QUALITY,
        DoctrineSetList::ANNOTATIONS_TO_ATTRIBUTES,
        DoctrineSetList::GEDMO_ANNOTATIONS_TO_ATTRIBUTES,
    ]);

    $rectorConfig->skip([
        FlipTypeControlToUseExclusiveTypeRector::class,
        ClosureToArrowFunctionRector::class,
        RemoveNonExistingVarAnnotationRector::class,
        RecastingRemovalRector::class => [
            __DIR__.'/src/*',
        ],
        RenameVariableToMatchMethodCallReturnTypeRector::class => [
            __DIR__.'/src/*',
        ],
        RenamePropertyToMatchTypeRector::class => [
            __DIR__.'/src/Entity/*',
        ],
        RenameParamToMatchTypeRector::class => [
            __DIR__.'/src/Admin/*',
        ],
        ActionSuffixRemoverRector::class,
    ]);
};

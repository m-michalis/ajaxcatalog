<?php

declare(strict_types=1);

/**
 * Rector configuration — automated PHP modernization.
 *
 * Run: composer run rector:test   (dry-run — shows proposed changes)
 * Run: composer run rector:fix    (apply changes)
 *
 * Targets PHP 8.2 (matches composer.json requirement).
 * Based on OpenMage LTS upstream config, adapted for module development.
 */

use Rector\Caching\ValueObject\Storage\FileCacheStorage;
use Rector\Config\RectorConfig;

return RectorConfig::configure()
    ->withPaths([
        __DIR__ . '/src',
        __DIR__ . '/tests',
    ])
    ->withCache(
        cacheDirectory: __DIR__ . '/.cache/.rector.result.cache',
        cacheClass: FileCacheStorage::class,
    )
    ->withPhpSets(
        php82: true,
    )
    ->withPreparedSets(
        deadCode: true,
        codeQuality: true,
        codingStyle: true,
        typeDeclarations: false,
        privatization: true,
        earlyReturn: true,
        phpunitCodeQuality: true,
    )
    ->withImportNames(removeUnusedImports: true)
    // Rector must be able to reflect Mage_* parent classes; they live in the
    // ephemeral openmage/ install, not in the project-root vendor dir.
    ->withBootstrapFiles([
        __DIR__ . '/openmage/vendor/autoload.php',
    ])
    ->withSkip([
        // NOTE: DeclareStrictTypesRector is deliberately absent from this list.
        // typeDeclarations is false above, so that rule is never registered, and
        // Rector exits non-zero when ->withSkip() names an unregistered rule:
        //   "This skipped rule is never registered. You can remove it from withSkip()"
        //
        // Every FQCN below was verified against the installed vendor tree. Rector
        // hard-fails on a skip entry that does not exist, so guessing a namespace
        // breaks the whole run — check the path before adding one.

        // Don't change method signatures (breaks OpenMage parent class contracts).
        \Rector\Php80\Rector\Class_\ClassPropertyAssignToConstructorPromotionRector::class,
        \Rector\CodingStyle\Rector\ClassMethod\FuncGetArgsToVariadicParamRector::class,

        // Fights ECS PhpUnitTestCaseStaticMethodCallsFixer(call_type: 'self'):
        // it rewrites self::assert*() back to $this->assert*(), so the two tools
        // would undo each other on every run.
        \Rector\PHPUnit\CodeQuality\Rector\Class_\PreferPHPUnitThisCallRector::class,

        // Both add declare(strict_types=1). OpenMage core is not strict-types safe,
        // and a strict test file blows up the moment it touches a Mage_* class.
        // Registered via the phpunitCodeQuality and codeQuality prepared sets.
        \Rector\PHPUnit\CodeQuality\Rector\StmtsAwareInterface\DeclareStrictTypesTestsRector::class,
        \Rector\TypeDeclaration\Rector\StmtsAwareInterface\SafeDeclareStrictTypesRector::class,

        // Test cases are meant to be extended (Tests\Base\AbstractTestCase).
        // NOTE the namespace: this rule ships under Privatization, NOT under
        // Rector\PHPUnit\*, and it is registered by the privatization set above.
        \Rector\Privatization\Rector\Class_\FinalizeTestCaseClassRector::class,

        // Rewrites require_once 'Mage/Checkout/controllers/CartController.php' to a
        // __DIR__-relative path that does not exist; core controllers resolve through
        // OpenMage's include path.
        \Rector\CodeQuality\Rector\Include_\AbsolutizeRequireAndIncludePathRector::class,
    ]);

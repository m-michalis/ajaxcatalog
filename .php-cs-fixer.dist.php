<?php

declare(strict_types=1);

/**
 * ECS (Easy Coding Standard) configuration.
 *
 * Matches the OpenMage LTS upstream coding style (PER-CS 2.0).
 * Run: composer run php-cs-fixer:test   (check)
 * Run: composer run php-cs-fixer:fix    (auto-fix)
 */

use PhpCsFixer\Fixer as PhpCsFixer;
use Symplify\EasyCodingStandard\Config\ECSConfig;
use Symplify\EasyCodingStandard\ValueObject\Set\SetList;

return ECSConfig::configure()
    ->withPaths([
        __DIR__ . '/src',
        __DIR__ . '/tests',
    ])
    ->withFileExtensions(['php'])
    ->withRootFiles()
    ->withCache(directory: __DIR__ . '/.cache/.ecs.cache')
    // withPhpCsFixerSets(perCS30: true) is a no-arg deprecated stub since ECS 13.3
    // ("Unknown named parameter $perCS30"); the PER-CS set list replaces it.
    ->withSets([SetList::PER_CS])
    ->withRules([
        PhpCsFixer\CastNotation\ModernizeTypesCastingFixer::class,
        // NOTE: deliberately UNCONFIGURED. Upstream passes
        // ['use_nullable_type_declaration' => true], but that option is deprecated in
        // php-cs-fixer 3.9x. Configuring a deprecated option routes through
        // ConfigurableFixerTrait's deprecation notice, which references
        // PhpCsFixer\Console\Application — a class ECS's scoped bundle does NOT ship
        // (it vendors only filesystem/finder/options-resolver, no symfony/console).
        // Result: ECS fatals before analysing a single file. Do NOT re-add the option.
        PhpCsFixer\FunctionNotation\NullableTypeDeclarationForDefaultNullValueFixer::class,
        PhpCsFixer\Operator\LogicalOperatorsFixer::class,
        PhpCsFixer\Phpdoc\NoEmptyPhpdocFixer::class,
        PhpCsFixer\Phpdoc\PhpdocAnnotationWithoutDotFixer::class,
        PhpCsFixer\Phpdoc\PhpdocIndentFixer::class,
        PhpCsFixer\Phpdoc\PhpdocParamOrderFixer::class,
        PhpCsFixer\Phpdoc\PhpdocSingleLineVarSpacingFixer::class,
        PhpCsFixer\Phpdoc\PhpdocTrimFixer::class,
        PhpCsFixer\Phpdoc\PhpdocTrimConsecutiveBlankLineSeparationFixer::class,
        PhpCsFixer\Phpdoc\PhpdocVarAnnotationCorrectOrderFixer::class,
        PhpCsFixer\Phpdoc\PhpdocVarWithoutNameFixer::class,
    ])
    ->withConfiguredRule(
        PhpCsFixer\Operator\OperatorLinebreakFixer::class,
        ['only_booleans' => false, 'position' => 'beginning'],
    )
    ->withConfiguredRule(
        PhpCsFixer\ClassNotation\OrderedTypesFixer::class,
        ['sort_algorithm' => 'alpha'],
    )
    ->withConfiguredRule(
        PhpCsFixer\Phpdoc\PhpdocAlignFixer::class,
        ['align' => 'vertical'],
    )
    ->withConfiguredRule(
        PhpCsFixer\Phpdoc\PhpdocOrderFixer::class,
        ['order' => ['param', 'return', 'throws', 'deprecated', 'see']],
    )
    ->withConfiguredRule(
        PhpCsFixer\Phpdoc\PhpdocOrderByValueFixer::class,
        ['annotations' => ['author', 'covers', 'group', 'method', 'throws', 'uses']],
    )
    ->withConfiguredRule(
        PhpCsFixer\Phpdoc\PhpdocScalarFixer::class,
        ['types' => ['boolean', 'callback', 'double', 'integer', 'real', 'str']],
    )
    ->withConfiguredRule(
        PhpCsFixer\Phpdoc\PhpdocTagCasingFixer::class,
        ['tags' => ['inheritDoc']],
    )
    ->withConfiguredRule(
        PhpCsFixer\Phpdoc\PhpdocTypesOrderFixer::class,
        ['sort_algorithm' => 'alpha'],
    )
    ->withConfiguredRule(
        PhpCsFixer\PhpUnit\PhpUnitTestCaseStaticMethodCallsFixer::class,
        ['call_type' => 'self'],
    )
    ->withConfiguredRule(
        PhpCsFixer\ClassNotation\SingleClassElementPerStatementFixer::class,
        ['elements' => ['const', 'property']],
    )
    ->withConfiguredRule(
        PhpCsFixer\StringNotation\SingleQuoteFixer::class,
        ['strings_containing_single_quote_chars' => false],
    )
    ->withConfiguredRule(
        PhpCsFixer\ControlStructure\TrailingCommaInMultilineFixer::class,
        ['after_heredoc' => true, 'elements' => ['arguments', 'array_destructuring', 'arrays']],
    )
    ->withConfiguredRule(
        PhpCsFixer\Whitespace\TypesSpacesFixer::class,
        ['space' => 'none'],
    );

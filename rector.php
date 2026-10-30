<?php

declare(strict_types=1);

use Pest\Rector\Set\PestSetList;
use Rector\CodeQuality\Rector\ClassMethod\LocallyCalledStaticMethodToNonStaticRector;
use Rector\CodingStyle\Rector\ClassMethod\MakeInheritedMethodVisibilitySameAsParentRector;
use Rector\Config\RectorConfig;
use Rector\DeadCode\Rector\ClassMethod\RemoveEmptyClassMethodRector;
use Rector\DeadCode\Rector\ClassMethod\RemoveUnusedPromotedPropertyRector;
use Rector\DeadCode\Rector\ClassMethod\RemoveUnusedPublicMethodParameterRector;
use Rector\DeadCode\Rector\Property\RemoveUselessVarTagRector;
use Rector\Php81\Rector\Property\ReadOnlyPropertyRector;
use Rector\Php82\Rector\Class_\ReadOnlyClassRector;

return RectorConfig::configure()
    // The same paths PHPStan analyzes.
    ->withPaths([
        __DIR__.'/config',
        __DIR__.'/routes',
        __DIR__.'/src',
        __DIR__.'/tests',
        __FILE__,
    ])
    // Keep the cache inside this tree (`build/` is gitignored). Rector's default is a directory
    // under the system temp directory shared by every checkout on the machine, so two worktrees
    // would read each other's cached results.
    ->withCache(cacheDirectory: __DIR__.'/build/rector')
    ->withSkip([
        // `readonly` is a semantic change, not a style one: it breaks framework code that mutates
        // after construction, cloning, and mocking. In a package it is also a public API change
        // for anything a consumer extends or writes to. Apply it deliberately, not on pain of a
        // red check.
        ReadOnlyClassRector::class,
        ReadOnlyPropertyRector::class,

        // Unsound for a package: Rector sees only the paths above, so it cannot see a static
        // call from `tests/`, or from a consuming application calling the public API.
        LocallyCalledStaticMethodToNonStaticRector::class,

        // Dead to Rector is not dead to a consumer. Each of these changes a signature an
        // application may call: removing an empty method, a parameter nothing here passes, or an
        // unused promoted property, which also removes that constructor parameter.
        RemoveEmptyClassMethodRector::class,
        RemoveUnusedPublicMethodParameterRector::class,
        RemoveUnusedPromotedPropertyRector::class,

        // These delete docblock tags that the `php-documentation` skill requires: an inline
        // `@var` pinning a value that arrives untyped, and `@return $this` beside `: static`.
        RemoveUselessVarTagRector::class,

        // Pest's `strict()` arch preset forbids protected methods, and PHP allows a subclass to
        // widen one, so an Eloquent model declares `casts()` public. This rule would narrow it
        // back to the parent's `protected`; it still applies everywhere else.
        MakeInheritedMethodVisibilitySameAsParentRector::class => [
            __DIR__.'/src/Models',
        ],
    ])
    ->withPreparedSets(
        deadCode: true,
        codeQuality: true,
        codingStyle: true,
        typeDeclarations: true,
        privatization: true,
        earlyReturn: true,
    )
    // The PHP sets for the version `composer.json` requires.
    ->withPhpSets()
    // The Laravel sets for the installed `laravel/framework` version.
    ->withComposerBased(laravel: true)
    // Pest's own rules for test code: idiomatic expectations, and no leftover `->only()` or debug
    // expectations. They match Pest calls only, so they leave `src/` alone.
    ->withSets([PestSetList::CODING_STYLE])
    // Promote inline fully qualified class names to `use` imports, leaving global short classes
    // alone. Pint owns removing unused imports, because Rector's removal does not treat a class
    // named only inside a docblock `{@see}` tag as used.
    ->withImportNames(importShortClasses: false, removeUnusedImports: false);

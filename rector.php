<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;
use Rector\Set\ValueObject\SetList;
use Rector\DeadCode\Rector\FunctionLike\NarrowWideUnionReturnTypeRector;

return RectorConfig::configure()
    ->withPaths([__DIR__ . '/src', __DIR__ . '/tests'])
    ->withSkipPath(__DIR__ . '/vendor')
    // The identifier allow-lists are an injection barrier; they stay as
    // explicit character classes, never shorthands that could widen.
    ->withPhpSets(php84: true)
    ->withSets([
        SetList::CODE_QUALITY,
        SetList::DEAD_CODE,
        SetList::TYPE_DECLARATION,
        SetList::EARLY_RETURN,
    ])
    // NarrowWideUnionReturnTypeRector cannot see a property hook. ForwardingSsh2::$curTimeout has a
    // `set` hook that rewrites 5 to POLL_TIMEOUT (0.005) while polling, so the closure in
    // ForwardingSsh2Test genuinely returns int|float -- and the test asserts both, 0.005 and 5.
    // Rector infers `int` from the literal assignment alone; applying that suggestion throws
    // "Return value must be of type int, float returned", verified 2026-11-13 by making the change
    // and watching the test fail. Scoped to the one file, so the rule still runs everywhere else.
    ->withSkip([
        NarrowWideUnionReturnTypeRector::class => [
            __DIR__ . '/tests/Unit/Tunnels/ForwardingSsh2Test.php',
        ],
    ])
    ->withImportNames(removeUnusedImports: true);

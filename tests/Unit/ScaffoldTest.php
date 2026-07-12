<?php

declare(strict_types=1);
use Illuminate\Translation\Translator;

it('merges the package config under the laranail.db-console namespace', function (): void {
    expect(config('laranail.db-console.default'))->toBe('primary')
        ->and(config('laranail.db-console.catalog.prefix'))->toBe('db_console_')
        ->and(config('laranail.db-console.secrets.driver'))->toBe('app_key')
        ->and(config('laranail.db-console.api.enabled'))->toBeFalse();
});

it('registers the db-console translation namespaces', function (): void {
    /** @var Translator $translator */
    $translator = app('translator');
    $loader = $translator->getLoader();

    expect($loader->namespaces())->toHaveKeys(['laranail/db-console', 'db-console']);
});

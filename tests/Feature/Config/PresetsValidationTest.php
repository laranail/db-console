<?php

declare(strict_types=1);

use Simtabi\Laranail\DBConsole\Exceptions\PresetMisconfigured;
use Simtabi\Laranail\DBConsole\Domain\Privileges\CustomPresets;
use Simtabi\Laranail\DBConsole\Providers\DBConsoleServiceProvider;

/*
 * config('laranail.db-console.presets') is validated against the privilege
 * allow-list at boot, as the config file says. The shipped default, [],
 * passes; anything it can never produce fails before a request is served.
 */

it('accepts the shipped default', function (): void {
    expect(config('laranail.db-console.presets'))->toBe([])
        ->and(CustomPresets::fromConfig([])->names())->toBe([]);
});

it('accepts a preset of allow-listed privileges', function (): void {
    $presets = CustomPresets::fromConfig(['reporting' => ['SELECT', 'SHOW VIEW']]);

    expect($presets->names())->toBe(['reporting'])
        ->and($presets->privileges('reporting'))->toHaveCount(2);
});

it('refuses a bad preset, naming it', function (mixed $presets, string $name): void {
    expect(fn (): CustomPresets => CustomPresets::fromConfig($presets))
        ->toThrow(PresetMisconfigured::class, "'{$name}'");
})->with([
    'a forbidden privilege'   => [['ops' => ['SELECT', 'SUPER']], 'ops'],
    'an unknown privilege'    => [['ops' => ['SELECTT']], 'ops'],
    'no privileges'           => [['empty' => []], 'empty'],
    'privileges not a list'   => [['scalar' => 'SELECT'], 'scalar'],
    'a non-string privilege'  => [['typed' => [1]], 'typed'],
    'a shipped preset name'   => [['read_only' => ['SELECT']], 'read_only'],
    'a name that is not text' => [[['SELECT']], '0'],
    'not an array'            => ['SELECT', 'presets'],
]);

it('fails the boot on a bad preset', function (): void {
    config()->set('laranail.db-console.presets', ['ops' => ['GRANT OPTION']]);

    expect(fn () => $this->app->register(DBConsoleServiceProvider::class, force: true))
        ->toThrow(PresetMisconfigured::class, "'ops'");
});

it('boots with the default presets', function (): void {
    $this->app->register(DBConsoleServiceProvider::class, force: true);
})->throwsNoExceptions();

<?php

declare(strict_types=1);

use Simtabi\Laranail\DBConsole\Exceptions\InsecureSecretDriver;
use Simtabi\Laranail\DBConsole\Secrets\SecretVaultManager;

/*
 * The boot-time guard: app_key is refused in production without the
 * explicit override (section 8, section 10). No catalog migration here —
 * the guard never touches the database — so switching the app environment
 * to production carries no migrate/teardown side effects.
 */

it('is a no-op outside production', function (): void {
    app(SecretVaultManager::class)->assertSecureForEnvironment();
})->throwsNoExceptions();

it('refuses app_key in production without the explicit override', function (): void {
    $this->app['env'] = 'production';
    config()->set('laranail.db-console.secrets.driver', 'app_key');
    config()->set('laranail.db-console.secrets.allow_app_key_in_production', false);

    expect(fn () => app(SecretVaultManager::class)->assertSecureForEnvironment())
        ->toThrow(InsecureSecretDriver::class);
});

it('allows app_key in production when the override is explicitly set', function (): void {
    $this->app['env'] = 'production';
    config()->set('laranail.db-console.secrets.driver', 'app_key');
    config()->set('laranail.db-console.secrets.allow_app_key_in_production', true);

    app(SecretVaultManager::class)->assertSecureForEnvironment();
})->throwsNoExceptions();

it('never blocks a stronger driver in production', function (): void {
    $this->app['env'] = 'production';
    config()->set('laranail.db-console.secrets.driver', 'kms');

    app(SecretVaultManager::class)->assertSecureForEnvironment();
})->throwsNoExceptions();

<?php

declare(strict_types=1);

use Simtabi\Laranail\DBConsole\Secrets\Secret;
use Simtabi\Laranail\DBConsole\Enums\SecretDriver;
use Simtabi\Laranail\DBConsole\Secrets\SecretVault;
use Simtabi\Laranail\DBConsole\Catalog\CatalogConnection;
use Simtabi\Laranail\DBConsole\Secrets\SecretVaultManager;

beforeEach(function (): void {
    $this->migrateCatalog();
});

it('registers a dedicated catalog connection separate from the app default', function (): void {
    $catalog = app(CatalogConnection::class);

    expect($catalog->name())->toBe('db_console_catalog')
        ->and(config('database.connections.db_console_catalog'))->not->toBeNull()
        ->and($catalog->name())->not->toBe(config('database.default'));
});

it('persists a secret through the app_key driver into the encrypted catalog table', function (): void {
    /** @var SecretVault $vault */
    $vault = app(SecretVault::class);

    $vault->store('server:prod', new Secret('the-real-admin-secret-value'));

    // Present in the catalog store, but as ciphertext — never plaintext.
    $stored = DB::connection('db_console_catalog')->table('db_console_secrets')->where('ref', 'server:prod')->value('payload');

    expect($stored)->not->toBeNull()
        ->and($stored)->not->toContain('the-real-admin-secret-value')
        ->and($vault->reveal('server:prod')->reveal())->toBe('the-real-admin-secret-value');
});

it('defaults to the app_key driver in the local/testing environment', function (): void {
    expect(app(SecretVaultManager::class)->driverName())->toBe(SecretDriver::AppKey);
});

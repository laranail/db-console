<?php

declare(strict_types=1);

use Simtabi\Laranail\DBConsole\Encryption\SqlCipherManager;
use Simtabi\Laranail\DBConsole\Enums\CatalogEncryptionMode;

describe('capability gating (section 8 decision tree)', function (): void {
    it('reports columns-only when the catalog is not SQLite', function (): void {
        $manager = new SqlCipherManager(sqlcipherEnabled: true, cipherKey: 'k', catalogIsSqlite: false);

        expect($manager->mode())->toBe(CatalogEncryptionMode::Columns)
            ->and($manager->wholeFileActive())->toBeFalse()
            ->and($manager->report()['reason'])->toContain('not on SQLite');
    });

    it('reports columns-only when SQLCipher is disabled', function (): void {
        $manager = new SqlCipherManager(sqlcipherEnabled: false, cipherKey: 'k', catalogIsSqlite: true);

        expect($manager->wholeFileActive())->toBeFalse()
            ->and($manager->report()['reason'])->toContain('disabled');
    });

    it('reports the missing-extension reason on a host without pdo_sqlcipher', function (): void {
        // pdo_sqlcipher is not installed in this environment.
        $manager = new SqlCipherManager(sqlcipherEnabled: true, cipherKey: 'k', catalogIsSqlite: true);

        expect($manager->extensionAvailable())->toBeFalse()
            ->and($manager->wholeFileActive())->toBeFalse()
            ->and($manager->report())->toMatchArray(['whole_file' => false])
            ->and($manager->report()['reason'])->toContain('pdo_sqlcipher');
    });

    it('reports the mode as a stable string for doctor', function (): void {
        $manager = new SqlCipherManager(sqlcipherEnabled: false, cipherKey: null, catalogIsSqlite: true);

        expect($manager->report()['mode'])->toBe('columns');
    });
});

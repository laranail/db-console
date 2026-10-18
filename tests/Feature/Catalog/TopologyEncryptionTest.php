<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Crypt;
use Simtabi\Laranail\DBConsole\Models\DbServer;
use Simtabi\Laranail\DBConsole\Models\DbAccount;
use Illuminate\Contracts\Encryption\DecryptException;

/*
 * catalog_encryption.encrypt_topology_columns: the topology columns
 * (DbServer::host, DbAccount::username and ::host) are encrypted when it is
 * true, the default, and written in plaintext when it is false. Reads accept
 * either form, so rows written under one setting stay readable under the
 * other.
 */

beforeEach(function (): void {
    $this->migrateCatalog();
});

function rawAccount(int|string $id): object
{
    return DB::connection('db_console_catalog')->table('db_console_accounts')->where('id', $id)->first();
}

function rawServer(int|string $id): object
{
    return DB::connection('db_console_catalog')->table('db_console_servers')->where('id', $id)->first();
}

it('encrypts the topology columns by default', function (): void {
    expect(config('laranail.db-console.catalog_encryption.encrypt_topology_columns'))->toBeTrue();

    $account = DbAccount::factory()->create(['username' => 'shop_user', 'host' => '10.0.%']);
    $server = DbServer::factory()->create(['host' => 'db.internal']);

    expect(Crypt::decryptString(rawAccount($account->id)->username))->toBe('shop_user')
        ->and(Crypt::decryptString(rawAccount($account->id)->host))->toBe('10.0.%')
        ->and(Crypt::decryptString(rawServer($server->id)->host))->toBe('db.internal')
        ->and($account->fresh()->username)->toBe('shop_user');
});

it('writes the topology columns in plaintext when false', function (): void {
    config()->set('laranail.db-console.catalog_encryption.encrypt_topology_columns', false);

    $account = DbAccount::factory()->create(['username' => 'shop_user', 'host' => '10.0.%']);
    $server = DbServer::factory()->create(['host' => 'db.internal']);

    expect(rawAccount($account->id)->username)->toBe('shop_user')
        ->and(rawAccount($account->id)->host)->toBe('10.0.%')
        ->and(rawServer($server->id)->host)->toBe('db.internal')
        ->and($account->fresh()->username)->toBe('shop_user');
});

it('reads rows written under the other setting', function (): void {
    $encrypted = DbAccount::factory()->create(['username' => 'enc_user']);

    config()->set('laranail.db-console.catalog_encryption.encrypt_topology_columns', false);
    $plain = DbAccount::factory()->create(['username' => 'plain_user']);

    expect($encrypted->fresh()->username)->toBe('enc_user');

    config()->set('laranail.db-console.catalog_encryption.encrypt_topology_columns', true);

    expect($plain->fresh()->username)->toBe('plain_user')
        ->and($encrypted->fresh()->username)->toBe('enc_user');
});

it('still refuses an encrypted value it cannot decrypt', function (): void {
    $account = DbAccount::factory()->create(['username' => 'shop_user']);
    $forged = base64_encode((string) json_encode(['iv' => 'x', 'value' => 'y', 'mac' => 'z', 'tag' => '']));
    DB::connection('db_console_catalog')->table('db_console_accounts')->where('id', $account->id)->update(['username' => $forged]);

    expect(fn () => $account->fresh()->username)->toThrow(DecryptException::class);
});

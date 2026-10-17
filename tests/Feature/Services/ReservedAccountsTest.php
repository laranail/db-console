<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Simtabi\Laranail\DBConsole\Services\AccountManager;

/*
 * MySqlEngine lists accounts with `SELECT User, Host FROM mysql.user`. SQLite
 * runs that same statement once a database is attached as `mysql`, so the
 * filtering is exercised through the real service and the real SQL, with no
 * server. The live-mysql group repeats it against MySQL and MariaDB.
 */

beforeEach(function (): void {
    Gate::before(fn ($user = null): bool => true);

    config()->set('database.connections.fake_mysql_admin', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
    $connection = DB::connection('fake_mysql_admin');
    $connection->statement("ATTACH DATABASE ':memory:' AS mysql");
    $connection->statement('CREATE TABLE mysql.user (User TEXT, Host TEXT)');
    foreach ([['doadmin', '%'], ['_dodb', 'localhost'], ['mysql.sys', 'localhost'], ['shop_app', '%'], ['doadmin_shop', '%']] as [$user, $host]) {
        $connection->insert('INSERT INTO mysql.user (User, Host) VALUES (?, ?)', [$user, $host]);
    }
});

function accountsOn(?string $hosting): array
{
    $server = ['engine' => 'mysql', 'connection' => 'fake_mysql_admin', 'tls' => ['enabled' => false]];
    if ($hosting !== null) {
        $server['hosting'] = $hosting;
    }
    config()->set('laranail.db-console.servers.fake', $server);

    return app(AccountManager::class)->list('fake');
}

it('lists every account on a self-hosted server, as it always has', function (): void {
    expect(accountsOn(null))->toBe(['_dodb@localhost', 'doadmin@%', 'doadmin_shop@%', 'mysql.sys@localhost', 'shop_app@%']);
});

it('hides the provider\'s and the engine\'s own accounts on a managed server', function (): void {
    expect(accountsOn('digitalocean'))->toBe(['doadmin_shop@%', 'shop_app@%']);
});

it('hides only the engine\'s own accounts on an unnamed provider', function (): void {
    expect(accountsOn('generic'))->toBe(['_dodb@localhost', 'doadmin@%', 'doadmin_shop@%', 'shop_app@%']);
});

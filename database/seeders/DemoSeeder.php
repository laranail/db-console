<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DBConsole\Database\Seeders;

use Illuminate\Database\Seeder;
use Simtabi\Laranail\DBConsole\Enums\EngineType;
use Simtabi\Laranail\DBConsole\Enums\PrivilegePreset;
use Simtabi\Laranail\DBConsole\Models\DbAccount;
use Simtabi\Laranail\DBConsole\Models\DbServer;
use Simtabi\Laranail\DBConsole\Models\Grant;
use Simtabi\Laranail\DBConsole\Models\ManagedDatabase;

/**
 * Opt-in demo data for kicking the tires against the Docker stack: a couple
 * of servers with sample databases, accounts, and grants. Never generates
 * real passwords (accounts store none). Published and run only on request.
 */
final class DemoSeeder extends Seeder
{
    public function run(): void
    {
        $server = DbServer::query()->firstOrCreate(
            ['name' => 'demo-mysql'],
            [
                'engine' => EngineType::Mysql,
                'host' => '127.0.0.1',
                'port' => 3306,
                'label' => 'Demo MySQL',
                'connection_ref' => 'db_console_admin',
                'is_managed' => true,
            ],
        );

        $database = ManagedDatabase::query()->firstOrCreate(
            ['server_name' => $server->name, 'name' => 'shop_demo'],
            ['charset' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_ci', 'is_managed' => true],
        );

        $account = DbAccount::query()->firstOrCreate(
            ['server_name' => $server->name, 'username_hash' => hash('sha256', 'demo-mysql|shop_user|10.0.%')],
            ['username' => 'shop_user', 'host' => '10.0.%', 'is_managed' => true],
        );

        Grant::query()->firstOrCreate(
            ['account_id' => $account->id, 'database_id' => $database->id],
            [
                'preset' => PrivilegePreset::AppStandard,
                'privileges' => array_map(
                    static fn ($p): string => $p->value,
                    PrivilegePreset::AppStandard->privileges(),
                ),
            ],
        );
    }
}

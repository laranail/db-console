<?php

declare(strict_types=1);

use Simtabi\Laranail\DBConsole\Models\Grant;
use Simtabi\Laranail\DBConsole\Models\DbServer;
use Simtabi\Laranail\DBConsole\Enums\EngineType;
use Simtabi\Laranail\DBConsole\Enums\GrantScope;
use Simtabi\Laranail\DBConsole\Models\DbAccount;
use Simtabi\Laranail\DBConsole\Enums\PrivilegePreset;
use Simtabi\Laranail\DBConsole\Exceptions\StaleModel;
use Simtabi\Laranail\DBConsole\Models\ManagedDatabase;

beforeEach(function (): void {
    $this->migrateCatalog();
});

describe('catalog wiring', function (): void {
    it('uses the dedicated catalog connection and the db_console_ prefix', function (): void {
        $server = DbServer::factory()->create(['name' => 'srv_prod']);

        expect($server->getConnectionName())->toBe('db_console_catalog')
            ->and($server->getTable())->toBe('db_console_servers')
            ->and(DB::connection('db_console_catalog')->table('db_console_servers')->count())->toBe(1);
    });

    it('assigns ULID primary keys', function (): void {
        $server = DbServer::factory()->create();

        expect($server->getKey())->toBeString()
            ->and(strlen((string) $server->getKey()))->toBe(26);   // ULID length
    });
});

describe('enum casts', function (): void {
    it('casts engine, preset, and scope columns to enums, never free strings', function (): void {
        $server = DbServer::factory()->create(['engine' => EngineType::Pgsql]);
        expect($server->fresh()->engine)->toBe(EngineType::Pgsql);

        $account = DbAccount::factory()->create(['server_name' => $server->name]);
        $database = ManagedDatabase::factory()->create(['server_name' => $server->name]);
        $grant = Grant::factory()->create([
            'account_id'  => $account->id,
            'database_id' => $database->id,
            'preset'      => PrivilegePreset::ReadWrite,
            'scope'       => GrantScope::Database,
        ]);

        expect($grant->fresh()->preset)->toBe(PrivilegePreset::ReadWrite)
            ->and($grant->fresh()->scope)->toBe(GrantScope::Database)
            ->and($grant->fresh()->privileges)->toBeArray();
    });
});

describe('encrypted topology columns', function (): void {
    it('stores account username/host as ciphertext, readable through the model', function (): void {
        $account = DbAccount::factory()->create([
            'username' => 'shop_user',
            'host'     => '10.0.%',
        ]);

        // Raw column is ciphertext; the model decrypts transparently.
        $raw = DB::connection('db_console_catalog')->table('db_console_accounts')
            ->where('id', $account->id)->first();

        expect($raw->username)->not->toBe('shop_user')
            ->and($account->fresh()->username)->toBe('shop_user')
            ->and($account->fresh()->host)->toBe('10.0.%');
    });
});

describe('optimistic locking (section 5 concurrency)', function (): void {
    it('starts new rows at version 1', function (): void {
        expect(DbServer::factory()->create()->version)->toBe(1);
    });

    it('bumps the version on a guarded save', function (): void {
        $server = DbServer::factory()->create(['label' => 'first']);
        $server->label = 'second';
        $server->saveOrConflict();

        expect($server->fresh()->version)->toBe(2)
            ->and($server->fresh()->label)->toBe('second');
    });

    it('raises StaleModel when two operators edit the same row', function (): void {
        $server = DbServer::factory()->create(['label' => 'original']);

        // Operator A and operator B both load the row.
        $a = DbServer::query()->find($server->id);
        $b = DbServer::query()->find($server->id);

        // A saves first.
        $a->label = 'changed-by-a';
        $a->saveOrConflict();

        // B's guarded save now conflicts (its loaded version is stale).
        $b->label = 'changed-by-b';
        expect(fn (): bool => $b->saveOrConflict())->toThrow(StaleModel::class);

        expect($server->fresh()->label)->toBe('changed-by-a');
    });
});

describe('relationships', function (): void {
    it('links server → databases → grants → account', function (): void {
        $server = DbServer::factory()->create(['name' => 'srv_rel']);
        $database = ManagedDatabase::factory()->create(['server_name' => 'srv_rel', 'name' => 'shop_db']);
        $account = DbAccount::factory()->create(['server_name' => 'srv_rel']);
        Grant::factory()->create(['account_id' => $account->id, 'database_id' => $database->id]);

        expect($server->databases)->toHaveCount(1)
            ->and($server->accounts)->toHaveCount(1)
            ->and($database->grants)->toHaveCount(1)
            ->and($account->grants)->toHaveCount(1)
            ->and($database->grants->first()->account->id)->toBe($account->id);
    });
});

describe('factories generate valid-by-construction data', function (): void {
    it('never generates a password column on accounts', function (): void {
        $account = DbAccount::factory()->create();

        expect($account->getAttributes())->not->toHaveKey('password');
    });

    it('generates grant privileges from the real preset enum', function (): void {
        $grant = Grant::factory()->preset(PrivilegePreset::ReadOnly)->make();

        expect($grant->privileges)->toBe(['select', 'show_view'])
            ->and($grant->preset)->toBe(PrivilegePreset::ReadOnly);
    });
});

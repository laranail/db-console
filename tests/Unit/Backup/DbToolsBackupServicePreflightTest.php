<?php

declare(strict_types=1);

use Simtabi\Laranail\DBConsole\Backup\BackupClientStatus;
use Simtabi\Laranail\DBConsole\Backup\DbToolsBackupService;
use Simtabi\Laranail\DbTools\Backup\Contracts\BackupManagerInterface;

/*
 * The adapter reads db-tools' preflight by method, so it works with a
 * db-tools that has one and returns null with one that does not (0.1.7 and
 * earlier). The managers here stand in for both.
 */

/**
 * A BackupManager stand-in whose preflight() answers as told and records
 * the connection it was asked about. Null $result means a db-tools without
 * preflight(): the method is then absent.
 */
function preflightManager(?object $result, bool $hasPreflight = true): object
{
    $manager = $hasPreflight
        ? new class($result) implements BackupManagerInterface
        {
            /** @var list<?string> */
            public array $asked = [];

            public function __construct(private readonly ?object $result) {}

            public function preflight(?string $connection = null): ?object
            {
                $this->asked[] = $connection;

                return $this->result;
            }

            public function backup(string $path, ?string $connection = null): bool
            {
                return true;
            }

            public function restore(string $path, ?string $connection = null): bool
            {
                return true;
            }

            public function supportsDriver(string $driver): bool
            {
                return true;
            }
        }
    : new class implements BackupManagerInterface
    {
        public function backup(string $path, ?string $connection = null): bool
        {
            return true;
        }

        public function restore(string $path, ?string $connection = null): bool
        {
            return true;
        }

        public function supportsDriver(string $driver): bool
        {
            return true;
        }
    };

    app()->instance(BackupManagerInterface::class, $manager);

    return $manager;
}

/**
 * The shape db-tools' BackupPreflight has: isReady() and public tool,
 * binary, message.
 */
function dbToolsPreflight(bool $ready, string $message): object
{
    return new class($ready, $message)
    {
        public string $tool = 'pg_dump';

        public string $binary = '/opt/homebrew/opt/libpq/bin/pg_dump';

        public function __construct(private readonly bool $ready, public string $message) {}

        public function isReady(): bool
        {
            return $this->ready;
        }
    };
}

beforeEach(function (): void {
    config()->set('database.connections.pg_admin', ['driver' => 'pgsql', 'host' => 'db.example.com', 'database' => 'defaultdb']);
    config()->set('laranail.db-console.servers.pg', ['engine' => 'pgsql', 'connection' => 'pg_admin', 'tls' => ['enabled' => false]]);
});

it('maps db-tools preflight for the server admin connection', function (): void {
    $manager = preflightManager(dbToolsPreflight(false, 'pg_dump 16 is older than the PostgreSQL 18 server.'));

    $status = app(DbToolsBackupService::class)->preflight('pg');

    expect($status)->toBeInstanceOf(BackupClientStatus::class)
        ->and($status->ready)->toBeFalse()
        ->and($status->tool)->toBe('pg_dump')
        ->and($status->binary)->toBe('/opt/homebrew/opt/libpq/bin/pg_dump')
        ->and($status->message)->toContain('older than the PostgreSQL 18 server')
        ->and($manager->asked)->toBe(['pg_admin']);
});

it('returns null with a db-tools that cannot preflight', function (): void {
    preflightManager(null, hasPreflight: false);

    expect(app(DbToolsBackupService::class)->preflight('pg'))->toBeNull();
});

it('does not check a server whose provider keeps the backups', function (): void {
    $manager = preflightManager(dbToolsPreflight(false, 'never asked'));
    config()->set('laranail.db-console.servers.pg.hosting', 'digitalocean');
    config()->set('laranail.db-console.backup.dump_on_managed', false);

    expect(app(DbToolsBackupService::class)->preflight('pg'))->toBeNull()
        ->and($manager->asked)->toBe([]);
});

it('checks a managed server that is dumped anyway', function (): void {
    $manager = preflightManager(dbToolsPreflight(true, 'pg_dump 18 can dump it.'));
    config()->set('laranail.db-console.servers.pg.hosting', 'digitalocean');
    config()->set('laranail.db-console.backup.dump_on_managed', true);

    expect(app(DbToolsBackupService::class)->preflight('pg')?->ready)->toBeTrue()
        ->and($manager->asked)->toBe(['pg_admin']);
});

it('does not check when backups are off', function (): void {
    $manager = preflightManager(dbToolsPreflight(false, 'never asked'));
    config()->set('laranail.db-console.backup.before_drop', false);

    expect(app(DbToolsBackupService::class)->preflight('pg'))->toBeNull()
        ->and($manager->asked)->toBe([]);
});

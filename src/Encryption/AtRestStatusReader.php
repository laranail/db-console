<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DBConsole\Encryption;

use Simtabi\Laranail\DBConsole\Domain\DbName;
use Simtabi\Laranail\DBConsole\Enums\AtRestStatus;
use Simtabi\Laranail\DBConsole\Enums\EngineType;
use Simtabi\Laranail\DBConsole\Servers\AdminConnection;
use Simtabi\Laranail\DBConsole\Servers\ServerRegistry;
use Throwable;

/**
 * Reads and reports at-rest encryption status per database (section 8, scope
 * 3). Read-only: DBConsole never enables, disables, or manages server-side
 * encryption — that is the DBA's job. A per-engine capability
 * (EncryptionCapabilities) gates what can even be read, so the UI only shows
 * what the target exposes.
 */
final readonly class AtRestStatusReader
{
    public function __construct(private ServerRegistry $registry) {}

    public function read(string $server, DbName $database): AtRestStatus
    {
        [$engine, $connection] = $this->registry->resolve($server);

        if (! $engine->capabilities()->encryption->canReadAtRestStatus) {
            return AtRestStatus::Unsupported;
        }

        try {
            return match ($engine->type()) {
                EngineType::Mysql, EngineType::Mariadb => $this->readMysql($connection, $database),
                EngineType::Sqlsrv => $this->readSqlServer($connection, $database),
                // Postgres has no built-in TDE; SQLite at-rest is the catalog's
                // SQLCipher story, not a managed-server readout.
                EngineType::Pgsql, EngineType::Sqlite => AtRestStatus::Unsupported,
            };
        } catch (Throwable) {
            return AtRestStatus::Unknown;
        }
    }

    private function readMysql(AdminConnection $connection, DbName $database): AtRestStatus
    {
        // A database is considered encrypted at rest when any of its InnoDB
        // tables are created with ENCRYPTION='Y'. Empty databases report
        // not_encrypted (nothing to encrypt yet).
        $encrypted = $connection->scalar(
            'SELECT COUNT(*) FROM information_schema.tables'
            . " WHERE table_schema = ? AND UPPER(COALESCE(CREATE_OPTIONS, '')) LIKE '%ENCRYPTION=''Y''%'",
            [$database->value],
            ['operation' => 'database.view'],
        );

        $total = $connection->scalar(
            'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = ?',
            [$database->value],
            ['operation' => 'database.view'],
        );

        if ((int) $total === 0) {
            return AtRestStatus::NotEncrypted;
        }

        return (int) $encrypted > 0 ? AtRestStatus::Encrypted : AtRestStatus::NotEncrypted;
    }

    private function readSqlServer(AdminConnection $connection, DbName $database): AtRestStatus
    {
        $state = $connection->scalar(
            'SELECT encryption_state FROM sys.dm_database_encryption_keys k'
            . ' JOIN sys.databases d ON k.database_id = d.database_id WHERE d.name = ?',
            [$database->value],
            ['operation' => 'database.view'],
        );

        // encryption_state 3 = encrypted; null/absent = not encrypted.
        return (int) $state === 3 ? AtRestStatus::Encrypted : AtRestStatus::NotEncrypted;
    }
}

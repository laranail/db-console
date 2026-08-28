<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DBConsole\Engines;

use Simtabi\Laranail\DBConsole\Enums\EngineType;

/**
 * Resolves an EngineType to its Engine implementation. One place maps the
 * enum to a class, so nothing else branches on database type.
 */
final class EngineFactory
{
    public function make(EngineType $type): Engine
    {
        return match ($type) {
            EngineType::Mysql   => new MySqlEngine,
            EngineType::Mariadb => new MariaDbEngine,
            EngineType::Pgsql   => new PostgresEngine,
            EngineType::Sqlsrv  => new SqlServerEngine,
            EngineType::Sqlite  => new SqliteEngine,
        };
    }
}

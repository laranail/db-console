<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DBConsole\Tests\Concerns;

use Illuminate\Contracts\Config\Repository;
use PDO;
use Throwable;

/**
 * Registers Docker-stack database connections for feature tests and skips
 * cleanly when a server is unreachable — so CI stays green without Docker
 * and the full matrix runs locally against `docker compose up`.
 *
 * Ports match docker/compose.yaml (host-mapped):
 *   mysql 33061 · mariadb 33062 · postgres 54329 · sqlsrv 14330
 */
trait InteractsWithDockerServers
{
    /**
     * @return array{host: string, port: int, database: string, username: string, password: string}
     */
    protected function mysqlParams(): array
    {
        return [
            'host' => (string) env('DB_CONSOLE_TEST_MYSQL_HOST', '127.0.0.1'),
            'port' => (int) env('DB_CONSOLE_TEST_MYSQL_PORT', 33061),
            'database' => (string) env('DB_CONSOLE_TEST_MYSQL_DATABASE', 'db_console_demo'),
            'username' => (string) env('DB_CONSOLE_TEST_MYSQL_USER', 'db_console_admin'),
            'password' => (string) env('DB_CONSOLE_TEST_MYSQL_PASSWORD', 'admin-secret-change-me'),
        ];
    }

    /**
     * Register a MySQL admin connection + DBConsole server, skipping the test
     * when the server cannot be reached.
     */
    protected function registerMysqlServer(string $server = 'docker-mysql', string $connection = 'db_console_admin'): void
    {
        $params = $this->mysqlParams();
        $this->skipUnlessReachable('mysql', $params['host'], $params['port'], $params['username'], $params['password']);

        /** @var Repository $config */
        $config = $this->app['config'];

        $config->set("database.connections.{$connection}", [
            'driver' => 'mysql',
            'host' => $params['host'],
            'port' => $params['port'],
            'database' => $params['database'],
            'username' => $params['username'],
            'password' => $params['password'],
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'prefix' => '',
        ]);

        $config->set("laranail.db-console.servers.{$server}", [
            'engine' => 'mysql',
            'connection' => $connection,
            'tls' => ['enabled' => false],
            'at_rest' => ['show_status' => true],
        ]);
    }

    /**
     * @return array{host: string, port: int, database: string, username: string, password: string}
     */
    protected function mariadbParams(): array
    {
        return [
            'host' => (string) env('DB_CONSOLE_TEST_MARIADB_HOST', '127.0.0.1'),
            'port' => (int) env('DB_CONSOLE_TEST_MARIADB_PORT', 33062),
            'database' => (string) env('DB_CONSOLE_TEST_MARIADB_DATABASE', 'db_console_demo'),
            'username' => (string) env('DB_CONSOLE_TEST_MARIADB_USER', 'db_console_admin'),
            'password' => (string) env('DB_CONSOLE_TEST_MARIADB_PASSWORD', 'admin-secret-change-me'),
        ];
    }

    /**
     * @return array{host: string, port: int, database: string, username: string, password: string}
     */
    protected function postgresParams(): array
    {
        return [
            'host' => (string) env('DB_CONSOLE_TEST_PGSQL_HOST', '127.0.0.1'),
            'port' => (int) env('DB_CONSOLE_TEST_PGSQL_PORT', 54329),
            'database' => (string) env('DB_CONSOLE_TEST_PGSQL_DATABASE', 'db_console_demo'),
            'username' => (string) env('DB_CONSOLE_TEST_PGSQL_USER', 'db_console_admin'),
            'password' => (string) env('DB_CONSOLE_TEST_PGSQL_PASSWORD', 'admin-secret-change-me'),
        ];
    }

    protected function registerMariadbServer(string $server = 'docker-mariadb', string $connection = 'db_console_mariadb'): void
    {
        $params = $this->mariadbParams();
        $this->skipUnlessReachable('mariadb', $params['host'], $params['port'], $params['username'], $params['password']);

        /** @var Repository $config */
        $config = $this->app['config'];
        $config->set("database.connections.{$connection}", [
            'driver' => 'mariadb',
            'host' => $params['host'], 'port' => $params['port'], 'database' => $params['database'],
            'username' => $params['username'], 'password' => $params['password'],
            'charset' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_ci', 'prefix' => '',
        ]);
        $config->set("laranail.db-console.servers.{$server}", [
            'engine' => 'mariadb', 'connection' => $connection, 'tls' => ['enabled' => false], 'at_rest' => ['show_status' => true],
        ]);
    }

    protected function registerPostgresServer(string $server = 'docker-postgres', string $connection = 'db_console_pgsql'): void
    {
        $params = $this->postgresParams();
        $this->skipUnlessReachablePgsql($params['host'], $params['port'], $params['database'], $params['username'], $params['password']);

        /** @var Repository $config */
        $config = $this->app['config'];
        $config->set("database.connections.{$connection}", [
            'driver' => 'pgsql',
            'host' => $params['host'], 'port' => $params['port'], 'database' => $params['database'],
            'username' => $params['username'], 'password' => $params['password'],
            'charset' => 'utf8', 'prefix' => '', 'search_path' => 'public', 'sslmode' => 'prefer',
        ]);
        $config->set("laranail.db-console.servers.{$server}", [
            'engine' => 'pgsql', 'connection' => $connection, 'tls' => ['enabled' => false], 'at_rest' => ['show_status' => true],
        ]);
    }

    protected function skipUnlessReachable(string $label, string $host, int $port, string $user, string $password): void
    {
        try {
            $pdo = new PDO(
                "mysql:host={$host};port={$port}",
                $user,
                $password,
                [PDO::ATTR_TIMEOUT => 2, PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
            );
            $pdo->query('SELECT 1');
        } catch (Throwable $e) {
            $this->markTestSkipped("Docker {$label} not reachable at {$host}:{$port} ({$e->getMessage()}). Run docker/compose.yaml.");
        }
    }

    protected function skipUnlessReachablePgsql(string $host, int $port, string $database, string $user, string $password): void
    {
        try {
            $pdo = new PDO(
                "pgsql:host={$host};port={$port};dbname={$database}",
                $user,
                $password,
                [PDO::ATTR_TIMEOUT => 2, PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
            );
            $pdo->query('SELECT 1');
        } catch (Throwable $e) {
            $this->markTestSkipped("Docker postgres not reachable at {$host}:{$port} ({$e->getMessage()}). Run docker/compose.yaml.");
        }
    }

    /**
     * A unique suffix so parallel/rerun tests never collide on names.
     */
    protected function uniqueSuffix(): string
    {
        return substr(bin2hex(random_bytes(4)), 0, 8);
    }
}

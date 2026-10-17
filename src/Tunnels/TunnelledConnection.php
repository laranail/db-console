<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DBConsole\Tunnels;

use InvalidArgumentException;
use Illuminate\Database\ConfigurationUrlParser;

/**
 * Rewrites a config/database.php connection to go through a tunnel's local
 * end, keeping everything else (credentials, database, TLS options) as it
 * was. Pure, so every engine's mapping is testable without a tunnel.
 */
final class TunnelledConnection
{
    /** The port name given to a Postgres socket: libpq connects to `<dir>/.s.PGSQL.<port>`. */
    public const int POSTGRES_SOCKET_PORT = 5432;

    /**
     * The admin connection's host and port before any tunnel, with a `url`
     * expanded, for working out the default remote target.
     *
     * @param array<string, mixed> $config
     *
     * @return array{host: ?string, port: ?int}
     */
    public static function origin(array $config): array
    {
        $config = self::expand($config);
        $host = $config['host'] ?? null;
        $port = $config['port'] ?? null;

        return [
            'host' => is_string($host) && $host !== '' ? $host : null,
            'port' => is_numeric($port) ? (int) $port : null,
        ];
    }

    public static function defaultPort(?string $driver): int
    {
        return match ($driver) {
            'pgsql'  => 5432,
            'sqlsrv' => 1433,
            default  => 3306,
        };
    }

    /**
     * The local socket file name the engine's client expects.
     */
    public static function socketName(?string $driver): string
    {
        return $driver === 'pgsql' ? '.s.PGSQL.' . self::POSTGRES_SOCKET_PORT : 'db.sock';
    }

    /**
     * @param array<string, mixed> $config
     *
     * @return array<string, mixed>
     */
    public static function rewrite(array $config, Endpoint $local, Endpoint $remote): array
    {
        $config = self::expand($config);
        $driver = $config['driver'] ?? null;

        if (isset($config['read']) || isset($config['write'])) {
            throw new InvalidArgumentException('a tunnel carries one host; this connection has a read/write split');
        }

        return match ($driver) {
            'mysql', 'mariadb' => self::mysql($config, $local),
            'pgsql'            => self::pgsql($config, $local, $remote),
            'sqlsrv'           => $local->isSocket()
                ? throw new InvalidArgumentException('SQL Server cannot connect over a Unix socket; set ssh.local to port')
                : [...$config, 'host' => $local->host, 'port' => $local->port],
            default => throw new InvalidArgumentException("a '{$driver}' connection cannot be tunnelled"),
        };
    }

    /**
     * Whether a connection reaches its server over a Unix socket: MySQL's
     * `unix_socket`, or a Postgres host that is a directory path.
     *
     * @param array<string, mixed> $config
     */
    public static function usesSocket(array $config): bool
    {
        $config = self::expand($config);

        return match ($config['driver'] ?? null) {
            'mysql', 'mariadb' => is_string($config['unix_socket'] ?? null) && $config['unix_socket'] !== '',
            'pgsql'            => is_string($config['host'] ?? null) && str_starts_with($config['host'], '/'),
            default            => false,
        };
    }

    /**
     * @param array<string, mixed> $config
     *
     * @return array<string, mixed>
     */
    private static function mysql(array $config, Endpoint $local): array
    {
        if ($local->isSocket()) {
            return [...$config, 'host' => 'localhost', 'port' => null, 'unix_socket' => $local->socket];
        }

        return [...$config, 'host' => $local->host, 'port' => $local->port, 'unix_socket' => ''];
    }

    /**
     * @param array<string, mixed> $config
     *
     * @return array<string, mixed>
     */
    private static function pgsql(array $config, Endpoint $local, Endpoint $remote): array
    {
        if ($local->isSocket()) {
            return [...$config, 'host' => dirname((string) $local->socket), 'port' => self::POSTGRES_SOCKET_PORT];
        }

        // Dialling 127.0.0.1 would make libpq verify the certificate against
        // 127.0.0.1. hostaddr dials the loopback while host stays the name
        // the certificate is checked against. Laravel copies host into the
        // DSN as is and PDO turns ';' into libpq's separator, so this is the
        // one place the extra keyword can travel.
        $verifiesHost = ($config['sslmode'] ?? null) === 'verify-full';
        if ($verifiesHost && ! $remote->isLocalToItsHost()) {
            return [...$config, 'host' => "{$remote->host};hostaddr={$local->host}", 'port' => $local->port];
        }

        return [...$config, 'host' => $local->host, 'port' => $local->port];
    }

    /**
     * @param array<string, mixed> $config
     *
     * @return array<string, mixed>
     */
    private static function expand(array $config): array
    {
        $config = new ConfigurationUrlParser()->parseConfiguration($config);
        unset($config['url']);

        return $config;
    }
}

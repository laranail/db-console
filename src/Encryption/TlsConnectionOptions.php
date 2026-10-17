<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DBConsole\Encryption;

use Pdo\Mysql;
use Simtabi\Laranail\DBConsole\Servers\ServerDefinition;

/**
 * Turns a server's `tls` block into the driver options Laravel's connectors
 * actually read. Until this existed the block was recorded and never applied,
 * so an admin connection could run in plaintext while doctor reported it as
 * secured.
 *
 * The block wins over config/database.php for the keys it sets, because TLS
 * is the one connection-security setting DBConsole owns. Keys it leaves unset
 * (a CA path that is null) are not removed, so a CA configured on the
 * connection itself still applies. With TLS disabled the connection config is
 * returned untouched.
 */
final readonly class TlsConnectionOptions
{
    /**
     * @param array<string, mixed> $connection a config/database.php connection array
     *
     * @return array<string, mixed>
     */
    public function apply(ServerDefinition $definition, array $connection): array
    {
        if (! $definition->tlsEnabled()) {
            return $connection;
        }

        $tls = $definition->tls;

        return match ($connection['driver'] ?? null) {
            'mysql', 'mariadb' => $this->mysql($tls, $connection),
            'pgsql'            => $this->pgsql($tls, $connection),
            'sqlsrv'           => [
                ...$connection,
                'encrypt'                  => 'yes',
                'trust_server_certificate' => $tls['verify'] ? 'no' : 'yes',
            ],
            default => $connection,
        };
    }

    /**
     * pdo_mysql only negotiates TLS when a CA, certificate, key, CA path or
     * cipher is set; the verify flag alone does not start it. Which is why the
     * TlsChecker measures the session rather than trusting this mapping.
     *
     * @param array{enabled: bool, verify: bool, ca: ?string, cert: ?string, key: ?string} $tls
     * @param array<string, mixed> $connection
     *
     * @return array<string, mixed>
     */
    private function mysql(array $tls, array $connection): array
    {
        // Without the extension no mysql connection can open at all, and the
        // Pdo\Mysql class (and its constants) does not exist.
        if (! extension_loaded('pdo_mysql')) {
            return $connection;
        }

        $ours = array_filter([
            Mysql::ATTR_SSL_CA   => $tls['ca'],
            Mysql::ATTR_SSL_CERT => $tls['cert'],
            Mysql::ATTR_SSL_KEY  => $tls['key'],
        ], static fn (?string $value): bool => $value !== null);

        $ours[Mysql::ATTR_SSL_VERIFY_SERVER_CERT] = $tls['verify'];

        /** @var array<int, mixed> $existing */
        $existing = (array) ($connection['options'] ?? []);

        // `+` keeps the left operand's value for a shared key, so ours win.
        return [...$connection, 'options' => $ours + $existing];
    }

    /**
     * @param array{enabled: bool, verify: bool, ca: ?string, cert: ?string, key: ?string} $tls
     * @param array<string, mixed> $connection
     *
     * @return array<string, mixed>
     */
    private function pgsql(array $tls, array $connection): array
    {
        return [
            ...$connection,
            ...array_filter([
                'sslrootcert' => $tls['ca'],
                'sslcert'     => $tls['cert'],
                'sslkey'      => $tls['key'],
            ], static fn (?string $value): bool => $value !== null),
            'sslmode' => $tls['verify'] ? 'verify-full' : 'require',
        ];
    }
}

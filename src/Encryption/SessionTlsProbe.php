<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DBConsole\Encryption;

use Throwable;
use Illuminate\Database\Connection;
use Illuminate\Database\ConnectionInterface;

/**
 * Reads the session's own encryption state with a query each engine answers
 * for an unprivileged user, so it works under a managed provider's admin
 * account (no SUPER, no superuser, no VIEW SERVER STATE).
 */
final readonly class SessionTlsProbe implements TlsProbe
{
    public function isEncrypted(ConnectionInterface $connection): ?bool
    {
        // Only a concrete Connection says which driver it speaks.
        if (! $connection instanceof Connection) {
            return null;
        }

        try {
            return match ($connection->getDriverName()) {
                'mysql', 'mariadb' => $this->mysql($connection),
                'pgsql'            => $this->pgsql($connection),
                'sqlsrv'           => $this->sqlsrv($connection),
                default            => null,
            };
        } catch (Throwable) {
            return null;
        }
    }

    private function mysql(ConnectionInterface $connection): ?bool
    {
        $row = $connection->selectOne("SHOW SESSION STATUS LIKE 'Ssl_cipher'");
        if ($row === null) {
            return null;
        }

        $values = array_values((array) $row);

        return ((string) ($values[1] ?? '')) !== '';
    }

    private function pgsql(ConnectionInterface $connection): ?bool
    {
        $ssl = $connection->scalar('SELECT ssl FROM pg_stat_ssl WHERE pid = pg_backend_pid()');

        // pdo_pgsql returns a PHP bool; other drivers' text form is 't'/'f'.
        return $ssl === null ? null : in_array($ssl, [true, 1, '1', 't', 'true'], true);
    }

    private function sqlsrv(ConnectionInterface $connection): ?bool
    {
        $option = $connection->scalar("SELECT CONNECTIONPROPERTY('encrypt_option')");

        return $option === null ? null : strtoupper((string) $option) === 'TRUE';
    }
}

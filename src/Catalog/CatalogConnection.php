<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DBConsole\Catalog;

use Illuminate\Contracts\Config\Repository as Config;
use Simtabi\Laranail\DBConsole\Encryption\SqlCipherManager;

/**
 * Owns the dedicated catalog connection (default db_console_catalog): builds
 * its Laravel connection config, and — when whole-file SQLCipher is active —
 * arranges the PRAGMA key on open so no caller ever deals with the cipher
 * key. Entirely separate from the app's default connection and from any
 * managed server's admin connection (section 9).
 */
final readonly class CatalogConnection
{
    public function __construct(
        private Config $config,
        private SqlCipherManager $sqlcipher,
    ) {}

    public function name(): string
    {
        return (string) $this->config->get('laranail.db-console.catalog.connection', 'db_console_catalog');
    }

    /**
     * The Laravel connection definition for the catalog. When whole-file
     * SQLCipher is active, the cipher key is applied via a PRAGMA on every
     * open through the connection's options, so callers never see it.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $database = (string) $this->config->get(
            'laranail.db-console.catalog.database',
            storage_path('db-console/catalog.sqlite'),
        );

        // No connection-level table prefix: the db_console_ prefix is applied
        // in the table NAMES (migrations + models) via catalog.prefix, so it
        // lives in exactly one place and never doubles up.
        $definition = [
            'driver' => 'sqlite',
            'database' => $database,
            'prefix' => '',
            'foreign_key_constraints' => true,
        ];

        if ($this->sqlcipher->wholeFileActive()) {
            // pdo_sqlcipher accepts the key via a PRAGMA immediately after
            // connect; expose it so the resolver applies it on open.
            $definition['driver'] = 'sqlite';
            $definition['sqlcipher_key'] = $this->sqlcipher->cipherKey();
        }

        return $definition;
    }

    /**
     * The PRAGMA statements to run on connection open (empty unless
     * whole-file encryption is active).
     *
     * @return list<string>
     */
    public function bootstrapPragmas(): array
    {
        $key = $this->sqlcipher->wholeFileActive() ? $this->sqlcipher->cipherKey() : null;

        if ($key === null || $key === '') {
            return [];
        }

        // The key is quoted as a SQL string literal; PRAGMA key must run
        // before any other statement on the connection.
        return ["PRAGMA key = '".str_replace("'", "''", $key)."'"];
    }
}

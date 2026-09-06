<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DBConsole\Encryption;

use PDO;
use Simtabi\Laranail\DBConsole\Enums\CatalogEncryptionMode;

/**
 * Detects whole-file SQLCipher availability and reports the active catalog
 * encryption mode (section 8, scope 2; section 9). Encrypted columns are
 * always available; whole-file SQLCipher is opt-in and capability-gated —
 * if pdo_sqlcipher is absent or no key is set, it is simply unavailable and
 * the package says so plainly rather than failing.
 *
 * This class never fatals: every method degrades to "columns only" and a
 * human-readable reason.
 */
final readonly class SqlCipherManager
{
    /**
     * @param bool $sqlcipherEnabled config toggle (catalog_encryption.sqlcipher.enabled)
     * @param ?string $cipherKey the DB_CONSOLE_CIPHER_KEY, if set
     * @param bool $catalogIsSqlite whether the catalog connection is SQLite
     */
    public function __construct(
        private bool $sqlcipherEnabled,
        private ?string $cipherKey,
        private bool $catalogIsSqlite,
    ) {}

    /**
     * Whether the pdo_sqlcipher extension is present on this host.
     */
    public function extensionAvailable(): bool
    {
        return in_array('sqlcipher', PDO::getAvailableDrivers(), true)
            || extension_loaded('pdo_sqlcipher');
    }

    /**
     * Whether whole-file encryption is actually active: SQLite catalog,
     * enabled, extension present, and a key set.
     */
    public function wholeFileActive(): bool
    {
        return $this->catalogIsSqlite
            && $this->sqlcipherEnabled
            && $this->extensionAvailable()
            && $this->cipherKey !== null
            && $this->cipherKey !== '';
    }

    public function mode(): CatalogEncryptionMode
    {
        return $this->wholeFileActive()
            ? CatalogEncryptionMode::WholeFile
            : CatalogEncryptionMode::Columns;
    }

    public function cipherKey(): ?string
    {
        return $this->cipherKey;
    }

    /**
     * A doctor-ready readout: the active mode plus, when whole-file is not
     * active, the precise reason and remediation (matching the section 8
     * decision tree).
     *
     * @return array{mode: string, whole_file: bool, reason: ?string}
     */
    public function report(): array
    {
        if ($this->wholeFileActive()) {
            return ['mode' => $this->mode()->value, 'whole_file' => true, 'reason' => null];
        }

        $reason = match (true) {
            ! $this->catalogIsSqlite      => 'catalog is not on SQLite; whole-file encryption is SQLite-specific (encrypted columns still apply)',
            ! $this->sqlcipherEnabled     => 'whole-file SQLCipher is disabled (catalog_encryption.sqlcipher.enabled=false)',
            ! $this->extensionAvailable() => 'the pdo_sqlcipher extension is not present; only encrypted columns are available',
            default                       => 'no DB_CONSOLE_CIPHER_KEY set; only encrypted columns are active',
        };

        return ['mode' => $this->mode()->value, 'whole_file' => false, 'reason' => $reason];
    }
}

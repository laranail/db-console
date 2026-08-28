<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DBConsole\Services\Results;

/**
 * The result of reconciling the catalog against the live server (section 5):
 * catalog rows whose objects no longer exist (orphans), live objects with no
 * catalog row (unmanaged), and grant differences. A report only — DBConsole
 * never auto-mutates the server to force a match.
 */
final readonly class ReconcileReport
{
    /**
     * @param list<string> $orphanDatabases catalog databases missing on the server
     * @param list<string> $unmanagedDatabases live databases with no catalog row
     * @param list<string> $orphanAccounts catalog accounts missing on the server
     * @param list<string> $unmanagedAccounts live accounts with no catalog row
     */
    public function __construct(
        public string $server,
        public array $orphanDatabases,
        public array $unmanagedDatabases,
        public array $orphanAccounts,
        public array $unmanagedAccounts,
        public int $adopted = 0,
    ) {}

    public function hasDrift(): bool
    {
        return $this->orphanDatabases !== []
            || $this->unmanagedDatabases !== []
            || $this->orphanAccounts !== []
            || $this->unmanagedAccounts !== [];
    }

    public function driftCount(): int
    {
        return count($this->orphanDatabases)
            + count($this->unmanagedDatabases)
            + count($this->orphanAccounts)
            + count($this->unmanagedAccounts);
    }
}

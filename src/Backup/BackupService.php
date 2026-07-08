<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DBConsole\Backup;

use Simtabi\Laranail\DBConsole\Domain\DbName;

/**
 * The backup seam. Snapshots a specific database before a destructive drop
 * (section 7). Backed by laranail/database-tools when present; when absent
 * the feature is disabled with a clear notice rather than silently skipped.
 */
interface BackupService
{
    /**
     * Snapshot a database and return the backup path, or null when backup is
     * disabled or unavailable (database-tools not installed).
     */
    public function snapshot(string $server, DbName $database): ?string;

    /**
     * Whether backups can actually run (config enabled AND database-tools
     * present). doctor and the drop flow read this.
     */
    public function available(): bool;

    /**
     * A human-readable reason when unavailable (for doctor / the notice).
     */
    public function unavailableReason(): ?string;
}

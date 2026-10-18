<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DBConsole\Backup;

use Simtabi\Laranail\DBConsole\Exceptions\DBConsoleException;

/**
 * A backup service that can check, without dumping, whether a server's
 * backup-before-drop could run now. Separate from BackupService so a host's
 * own implementation of that contract keeps working; doctor asks for this
 * one with instanceof.
 */
interface PreflightsBackups
{
    /**
     * Null when there is nothing to check: backups are off, the server's
     * provider keeps its own point-in-time recovery, or the installed backup
     * tooling cannot preflight.
     *
     * @throws DBConsoleException When the server cannot be resolved
     */
    public function preflight(string $server): ?BackupClientStatus;
}

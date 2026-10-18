<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DBConsole\Doctor;

use Throwable;
use Simtabi\Laranail\DBConsole\Backup\BackupService;
use Illuminate\Contracts\Config\Repository as Config;
use Simtabi\Laranail\DBConsole\Backup\PreflightsBackups;
use Simtabi\Laranail\DBConsole\Backup\BackupClientStatus;

/**
 * Whether a server's backup-before-drop could run now: its dump client runs
 * and is new enough. Without this a missing mysqldump, or a pg_dump older
 * than the server, surfaces only when a drop needs the backup, and with
 * `backup.abort_drop_on_failure` on (the default) the drop is then refused.
 *
 * An error when that refusal would happen, a warning when the drop would go
 * ahead without a backup. Silent when there is nothing to check: backups
 * off, a provider that keeps its own point-in-time recovery, or a backup
 * service that cannot preflight.
 */
final readonly class BackupClientCheck
{
    public function __construct(
        private BackupService $backups,
        private Config $config,
    ) {}

    /**
     * @return list<DoctorFinding>
     */
    public function findings(string $server): array
    {
        if (! $this->backups instanceof PreflightsBackups) {
            return [];
        }

        $check = "server:{$server}:backup-client";

        try {
            $status = $this->backups->preflight($server);
        } catch (Throwable $e) {
            return [DoctorFinding::warning($check, "Could not check the backup client for '{$server}': {$e->getMessage()}")];
        }

        if (! $status instanceof BackupClientStatus) {
            return [];
        }

        if ($status->ready) {
            return [DoctorFinding::ok($check, "Backup client for '{$server}': {$status->message}")];
        }

        $refuses = (bool) $this->config->get('laranail.db-console.backup.abort_drop_on_failure', true);
        $consequence = $refuses
            ? 'a drop of a non-empty database on it will be refused, because its backup cannot run'
            : 'a drop of a non-empty database on it will go ahead without a backup';
        $message = "Backup client {$status->tool} for '{$server}' is not usable, so {$consequence}.";

        return [$refuses
            ? DoctorFinding::error($check, $message, $status->message)
            : DoctorFinding::warning($check, $message, $status->message)];
    }
}

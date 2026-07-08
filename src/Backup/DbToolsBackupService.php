<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DBConsole\Backup;

use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;
use Simtabi\Laranail\DbTools\Backup\Contracts\BackupManagerInterface;
use Simtabi\Laranail\DBConsole\Domain\DbName;
use Simtabi\Laranail\DBConsole\Enums\Severity;
use Simtabi\Laranail\DBConsole\Logging\DBConsoleLogger;
use Simtabi\Laranail\DBConsole\Servers\ServerRegistry;
use Throwable;

/**
 * Backup-before-drop via laranail/db-tools. Snapshots the specific
 * database by pointing a transient connection (cloned from the server's admin
 * connection) at it and asking db-tools' BackupManager to dump it. If
 * db-tools is not installed, available() is false and the drop flow
 * proceeds with a logged notice (never a silent skip).
 */
final readonly class DbToolsBackupService implements BackupService
{
    private const string BACKUP_MANAGER = BackupManagerInterface::class;

    public function __construct(
        private Container $container,
        private Config $config,
        private ServerRegistry $registry,
        private FilesystemFactory $filesystem,
        private DBConsoleLogger $log,
    ) {}

    public function snapshot(string $server, DbName $database): ?string
    {
        if (! $this->available()) {
            $this->log->write(
                Severity::Notice,
                'database.backup.skipped',
                ['server' => $server, 'target' => $database->value, 'reason' => $this->unavailableReason()],
            );

            return null;
        }

        $adminConnection = $this->registry->definition($server)->connection;
        $adminConfig = (array) $this->config->get("database.connections.{$adminConnection}", []);

        // Transient connection scoped to the target database, on the same
        // admin server.
        $scoped = "db_console_backup_{$server}";
        $this->config->set("database.connections.{$scoped}", [...$adminConfig, 'database' => $database->value]);

        $path = $this->backupPath($server, $database);

        try {
            /** @var BackupManagerInterface $manager */
            $manager = $this->container->make(self::BACKUP_MANAGER);
            $manager->backup($this->absolutePath($path), $scoped);

            return $path;
        } catch (Throwable $e) {
            // A backup failure must not block a legitimate drop, but it is
            // significant — log it loudly. The operator saw the drop preview.
            $this->log->write(
                Severity::Warning,
                'database.backup.failed',
                ['server' => $server, 'target' => $database->value, 'error' => $e->getMessage()],
            );

            return null;
        }
    }

    public function available(): bool
    {
        return $this->configEnabled() && interface_exists(self::BACKUP_MANAGER);
    }

    public function unavailableReason(): ?string
    {
        if (! $this->configEnabled()) {
            return 'backups are disabled (laranail.db-console.backup.enabled=false)';
        }

        if (! interface_exists(self::BACKUP_MANAGER)) {
            return 'laranail/db-tools is not installed, so backup-before-drop is unavailable';
        }

        return null;
    }

    private function configEnabled(): bool
    {
        return (bool) $this->config->get('laranail.db-console.backup.enabled', true)
            && (bool) $this->config->get('laranail.db-console.backup.before_drop', true);
    }

    private function backupPath(string $server, DbName $database): string
    {
        return sprintf('db-console/backups/%s/%s.sql', $server, $database->value);
    }

    private function absolutePath(string $path): string
    {
        $disk = (string) $this->config->get('laranail.db-console.backup.disk', 'local');

        return (string) $this->filesystem->disk($disk)->path($path);
    }
}

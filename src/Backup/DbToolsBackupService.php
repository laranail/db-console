<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DBConsole\Backup;

use Throwable;
use RuntimeException;
use Illuminate\Support\Carbon;
use Illuminate\Contracts\Container\Container;
use Simtabi\Laranail\DBConsole\Domain\DbName;
use Simtabi\Laranail\DBConsole\Enums\Severity;
use Illuminate\Database\ConfigurationUrlParser;
use Simtabi\Laranail\DBConsole\Enums\EngineType;
use Illuminate\Contracts\Config\Repository as Config;
use Simtabi\Laranail\DBConsole\Servers\ServerRegistry;
use Simtabi\Laranail\DBConsole\Exceptions\BackupFailed;
use Simtabi\Laranail\DBConsole\Logging\DBConsoleLogger;
use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;
use Simtabi\Laranail\DbTools\Backup\Contracts\BackupManagerInterface;

/**
 * Backup-before-drop via laranail/db-tools. Snapshots the specific
 * database by pointing a transient connection (cloned from the server's admin
 * connection) at it and asking db-tools' BackupManager to dump it. If
 * db-tools is not installed, available() is false and the drop flow
 * proceeds with a logged notice (never a silent skip).
 *
 * A backup that was attempted and failed throws BackupFailed, so the drop
 * does not run; `backup.abort_drop_on_failure=false` restores the old
 * log-and-drop. Each snapshot gets its own timestamped file, named for the
 * format db-tools writes (`.dump` for PostgreSQL's custom format, `.sql`
 * otherwise), so a later restore picks the right tool.
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

        $definition = $this->registry->definition($server);

        // Resolving opens the server's SSH tunnel, if it has one, and points
        // the admin connection at it. Read before that, the config still
        // names the remote host and the dump would go around the tunnel.
        $this->registry->resolve($server);

        /** @var array<string, mixed> $adminConfig */
        $adminConfig = (array) $this->config->get("database.connections.{$definition->connection}", []);

        // Transient connection scoped to the target database, on the same
        // admin server. A `url` is expanded and dropped first: Laravel lets a
        // url's database override the `database` key, so a DATABASE_URL admin
        // connection would otherwise dump the url's database, not the target.
        // parseConfiguration() also removes the `url` key.
        $scopedConfig = new ConfigurationUrlParser()->parseConfiguration($adminConfig);
        $scopedConfig['database'] = $database->value;

        // Laravel's MySQL socket key is unix_socket; db-tools reads socket.
        if (is_string($scopedConfig['unix_socket'] ?? null) && $scopedConfig['unix_socket'] !== '') {
            $scopedConfig['socket'] ??= $scopedConfig['unix_socket'];
        }

        $scoped = "db_console_backup_{$server}";
        $this->config->set("database.connections.{$scoped}", $scopedConfig);

        $path = $this->backupPath($server, $database, $definition->engine);

        try {
            /** @var BackupManagerInterface $manager */
            $manager = $this->container->make(self::BACKUP_MANAGER);
            $manager->backup($absolute = $this->absolutePath($path), $scoped);
            $this->prune($absolute, $database);

            return $path;
        } catch (Throwable $e) {
            $this->log->write(
                Severity::Warning,
                'database.backup.failed',
                ['server' => $server, 'target' => $database->value, 'error' => $e->getMessage()],
            );

            if ((bool) $this->config->get('laranail.db-console.backup.abort_drop_on_failure', true)) {
                throw BackupFailed::beforeDrop($server, $database->value, $e);
            }

            return null;
        } finally {
            $this->config->set("database.connections.{$scoped}");
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

    /**
     * Delete this database's earlier snapshots older than
     * `backup.retention_days` (0 keeps them all). Only files this service
     * named are considered, never anything else in the directory, and never
     * the snapshot just taken.
     */
    private function prune(string $latest, DbName $database): void
    {
        $days = (int) $this->config->get('laranail.db-console.backup.retention_days', 30);
        if ($days <= 0) {
            return;
        }

        $cutoff = Carbon::now()->subDays($days)->getTimestamp();
        // No GLOB_BRACE: musl (Alpine) builds of PHP do not have it.
        foreach (glob(dirname($latest) . '/' . $database->value . '-*') ?: [] as $file) {
            if ($file !== $latest
                && preg_match('/^' . preg_quote($database->value, '/') . '-\d{8}T\d{6}Z\.(sql|dump)$/', basename($file)) === 1
                && (int) filemtime($file) < $cutoff) {
                @unlink($file);
            }
        }
    }

    private function backupPath(string $server, DbName $database, EngineType $engine): string
    {
        return sprintf(
            'db-console/backups/%s/%s-%s.%s',
            $server,
            $database->value,
            Carbon::now()->utc()->format('Ymd\THis\Z'),
            $engine === EngineType::Pgsql ? 'dump' : 'sql',
        );
    }

    /**
     * The dump tools write to a local path, so the disk must be local: on S3
     * path() returns the object key, and the dump would land relative to the
     * working directory instead.
     */
    private function absolutePath(string $path): string
    {
        $disk = (string) $this->config->get('laranail.db-console.backup.disk', 'local');
        $driver = $this->config->get("filesystems.disks.{$disk}.driver");

        if ($driver !== 'local') {
            throw new RuntimeException(
                "laranail.db-console.backup.disk '{$disk}' uses the '" . $driver . "' driver; backups need a local disk.",
            );
        }

        $directory = dirname($this->filesystem->disk($disk)->path($path));
        if (! is_dir($directory) && ! @mkdir($directory, 0700, true) && ! is_dir($directory)) {
            throw new RuntimeException("Could not create the backup directory '{$directory}'.");
        }

        return (string) $this->filesystem->disk($disk)->path($path);
    }
}

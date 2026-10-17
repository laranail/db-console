# Backup before drop

Before it drops a non-empty database, DBConsole snapshots it through `laranail/db-tools`, and a snapshot that fails stops the drop.

## Overview

`DatabaseManager::drop()` checks whether the target is empty. When it is not, `BackupService::snapshot()` (default `DbToolsBackupService`) points a transient connection, cloned from the server's admin connection, at the target and asks db-tools' `BackupManager` to dump it. The clone keeps the admin connection's TLS settings. Its `url` (`DATABASE_URL`) is expanded first, because Laravel would otherwise let the url's database override the target.

| Outcome | What happens |
|---|---|
| snapshot written | `DatabaseBackedUp` is dispatched with the path, then the drop runs |
| backups disabled, or db-tools not installed | a `database.backup.skipped` notice is logged, then the drop runs |
| snapshot attempted and failed | `BackupFailed` is thrown, the drop does **not** run, and it is audited as a failed drop (`OperationFailed`) |

`backup.abort_drop_on_failure=false` restores the old behaviour: log the failure as a warning and drop anyway.

## Files

Snapshots land on the `backup.disk` disk at `db-console/backups/{server}/{database}-{UTC timestamp}.{ext}`: `.dump` for PostgreSQL (pg_dump's custom format, which db-tools restores with `pg_restore`) and `.sql` otherwise. Each snapshot is its own file.

The disk must use the `local` driver, because the dump tools write to a local path. Any other driver fails the snapshot, and with it the drop.

After each successful snapshot, earlier snapshots of the same database older than `backup.retention_days` are deleted. `0` keeps them all. Only files matching the name above are considered.

## Configuration

| Key | Default | |
|---|---|---|
| `backup.enabled` | `true` | master switch |
| `backup.before_drop` | `true` | snapshot before a drop |
| `backup.abort_drop_on_failure` | `true` (env `DB_CONSOLE_BACKUP_ABORT_DROP_ON_FAILURE`) | a failed snapshot stops the drop |
| `backup.disk` | `local` (env `DB_CONSOLE_BACKUP_DISK`) | a disk with the `local` driver |
| `backup.retention_days` | `30` | prune older snapshots of the same database; `0` keeps all |
| `backup.dump_on_managed` | `false` (env `DB_CONSOLE_BACKUP_DUMP_ON_MANAGED`) | also dump servers whose [hosting](servers.md#hosting) is managed |

On a server whose [hosting](servers.md#hosting) is managed, no dump is taken by default: the provider's point-in-time recovery restores to any second in its retention window, which a snapshot taken at drop time cannot. The drop logs `database.backup.provider` with the hosting and a `restore_point` timestamp, which is the moment to restore to. Set `backup.dump_on_managed` to also keep a copy you hold yourself. db-tools' [backup & restore](https://opensource.simtabi.com/documentation/laranail/db-tools/tools/backup-restore) page covers the flags it passes to `mysqldump` and `pg_dump` for managed servers.

---

[← Docs index](../../README.md#documentation)

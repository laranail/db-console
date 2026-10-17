# Upgrading

## Unreleased

### TLS is applied and measured

The `tls` block on a server is now written into its admin connection, and
`TlsChecker` reports what the live session negotiated instead of what config
says. Three things can change on upgrade:

- **MySQL/MariaDB with `tls.enabled=true` and no `tls.ca` now fails `doctor`
  on a remote server.** pdo_mysql never started TLS without a CA, certificate
  or key, so these sessions were always plaintext; they are now reported as
  such. Set `DB_CONSOLE_PRIMARY_TLS_CA` (or `tls.ca`) to the server's CA.
- **PostgreSQL with `tls.enabled=true` now connects with `sslmode=require`**
  (`verify-full` when `verify` is on). A server without SSL that used to
  accept a plaintext connection now refuses it.
- **`encryption:status` and `TlsChecker::status()` open the admin
  connection.** An unreachable server reports `unmeasured`.

A connection configured only by `url` (`DATABASE_URL`) is no longer treated
as local, so it is no longer exempt from the TLS findings.

### Backup before drop

- **A failed backup now stops the drop** and throws `BackupFailed`. Set
  `DB_CONSOLE_BACKUP_ABORT_DROP_ON_FAILURE=false` to keep dropping after a
  logged warning.
- **Backup files are timestamped**, and PostgreSQL ones end in `.dump`. Tooling
  that read `db-console/backups/{server}/{database}.sql` must list the
  directory instead. Snapshots older than `backup.retention_days` (30) are
  deleted.
- **The backup disk must use the `local` driver**; any other fails the drop.

### PostgreSQL provisioning

Postgres provisioning works on any server now, which changes what some calls do:

- **Dropping a role reassigns what it owns to the admin.** Before, `DROP ROLE` failed while the role
  owned anything. Now its objects in every database become the admin's (`REASSIGN OWNED BY ... TO
  CURRENT_USER`). Nothing is deleted, and its privileges are revoked. The admin must hold ADMIN
  OPTION on the role, which it does for roles it created.
- **Dropping a database terminates its sessions** (`DROP DATABASE ... WITH (FORCE)`, PostgreSQL 13
  or later): those the admin may signal. A session of a role it cannot signal still blocks it.
- **Revoking a privilege revokes the Postgres privilege it maps to**, even when another privilege
  the role holds maps to the same one: `show_view` and `select` are both `SELECT`; `create`,
  `create_view` and `create_routine` are all schema `CREATE`. Revoke fails closed.
- **Grants go to the target database.** If you worked around the old behaviour by granting inside
  the target yourself, those grants still stand. `ALTER`, `DROP`, `INDEX`, `ALTER ROUTINE`, `LOCK
  TABLES` and `EVENT` now grant nothing on Postgres, rather than `SELECT`, `CREATE` or `EXECUTE` on
  tables. The role holds them on the tables it creates itself.
- **An empty Postgres database is no longer backed up before a drop**, the same as on MySQL.
- **`DatabaseManager`, `AccountManager` and `PrivilegeManager` take a `ScopedConnections`** as a new
  last constructor argument. Resolved from the container, nothing changes. Code that calls `new` on
  one of them must pass it.
- **`AdminConnection::run()` throws `LogicException` for a statement scoped to another database.**
  Engines in this package only scope Postgres statements. An engine of your own is affected only if
  it calls `Statement::inDatabase()`.

## From pre-release to 0.x

No published releases yet. This file will document breaking changes and
migration steps between released versions.

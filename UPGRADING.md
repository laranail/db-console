# Upgrading

## Unreleased

### Existing accounts and databases are refused

Creating an account or database that is already on the server used to succeed quietly with
`alreadyExisted: true`, and an account kept its old password whatever you passed. It now **fails
with `AccountAlreadyExists` / `DatabaseAlreadyExists`** (HTTP 409), changes nothing, and notifies
the security recipients.

- **To keep adopting**, pass `ExistingResourcePolicy::Adopt` as the new last argument of
  `AccountManager::create()`, `DatabaseManager::create()` or `ProvisioningWizard::provision()`,
  `--on-existing=adopt` on the CLI, `"on_existing": "adopt"` to the API, or set
  `DB_CONSOLE_ACCOUNTS_ON_EXISTING` / `DB_CONSOLE_DATABASES_ON_EXISTING` (globally, or per server
  under `servers.<name>.accounts|databases.on_existing`).
- **Adopting needs the new `resource.adopt` permission**, which only Owner holds by default. An
  install seeded before this release has no `resource.adopt` row, so even Owner is refused until you
  re-run `php artisan laranail::db-console.install` (its role seeding is idempotent; it re-syncs
  the shipped roles' permissions, so a change you made to a shipped role is reset). Grant it to a
  custom role with `role:create`.
- **A wizard re-run without a password** is the common case: it now needs `adopt` and
  `resource.adopt`. It never replaces the account's password.
- **`adopt_and_apply`** replaces an adopted account's password with the one supplied, and needs
  `account.rotate` too. Anything logging in with the old password stops working.
- A CLI run that is interactive and finds the resource asks what to do. Scripts should pass
  `--no-interaction` (the configured policy applies) or `--on-existing`.

### Notifications are queued and can use Slack or an inbox

- `notifications.queue` was always `true` and never read; notifications were sent in the request.
  **They are now queued**, so a queue worker must run for them to go out. Set
  `notifications.queue` to `false` to keep sending in the request, or to a queue name.
- `notifications.quiet_hours` is read: routine notifications are held until the window ends.
- `alerts.channel` is read: Warning and Critical events go out on it rather than on their
  category's channels. The default, `mail`, is what they used before.
- New: `notifications.channels`, `notifications.slack.route` (install
  `laravel/slack-notification-channel`) and `notifications.database.users`.

### The REST API reads the keys its config documents

- The API now authenticates with **`api.auth`** and mounts under **`api.path`**. Until now it read
  `api.guard` and `api.prefix`, which the shipped config never defined, so `sanctum` and
  `api/db-console` always applied. If you set `DB_CONSOLE_API_AUTH` or `DB_CONSOLE_API_PATH`, they
  take effect now.
- If your published config has `api.guard` or `api.prefix` and no `api.auth` / `api.path`, those
  are still honoured, with a deprecation notice. Rename them. If it has both, the documented key
  wins.
- **`api.rate_limit` is enforced** (default `60,1`: 60 requests a minute per caller). Set it to `''`
  to switch it off.
- `api.require_https` and `api.confirm_destructive` are read; both default to `true`, as the API
  behaved before.

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

### The recommended MySQL and MariaDB admin

An admin created from the previous recommendation cannot grant the `app_standard` or `full` preset,
so the wizard fails at its grant step. Give it the privileges it was missing, with grant option:

```sql
GRANT CREATE USER ON *.* TO 'db_console_admin'@'%';
GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, ALTER, DROP, INDEX, REFERENCES, CREATE TEMPORARY TABLES, LOCK TABLES, EXECUTE, CREATE VIEW, SHOW VIEW, CREATE ROUTINE, ALTER ROUTINE, EVENT, TRIGGER ON *.* TO 'db_console_admin'@'%' WITH GRANT OPTION;
```

`RELOAD` is no longer recommended, because nothing db-console runs uses it. An admin that already
has it keeps working; `REVOKE RELOAD ON *.* FROM 'db_console_admin'@'%'` takes it away.

### Wizard rollback

When a provisioning wizard fails, its rollback no longer drops a database or account that existed
before the run. If you relied on a failed run clearing an existing empty database, drop it yourself.

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
- **A Postgres grant is all or nothing, and can throw `GrantNotApplied`.** Where the admin lacks the
  grant option on something the set covers (a database or schema another role owns), nothing is
  granted and the exception names what was missing. Before, part of the grant went through and the
  call reported success. On PostgreSQL 14 and earlier, PUBLIC holds `CREATE` on schema `public`, so
  that case cannot arise there; it also means any role can create tables in `public` on those
  versions, which a non-superuser admin cannot change.
- **A Postgres role drop refuses up front** with `InsufficientPrivilege` when the role has objects
  or privileges in a database the admin cannot connect to. Before, it failed part way.
- **New last constructor arguments**, resolved from the container as before: `DatabaseManager` and
  `PrivilegeManager` take a `ScopedConnections`, and `AccountManager` takes an
  `OwnershipReleasingDrop`. Code that calls `new` on one of them must pass it.
- **`OwnershipReleasingEngine` gained five methods** (`holdsPrivilegesOf`, `takeMembership`,
  `releaseMembership`, `releaseOwnershipIn`, `dropReleasedAccount`), and `dependentDatabases()`
  returns a second column, `can_connect`. An engine of your own implementing it must add them.
- **`showGrants()` returns different lines on Postgres.** Each is `<kind> <database> <object>
  <privilege>` (`table shop public.orders SELECT`), where it was `<schema> <table> <privilege>` for
  tables in the admin's own database only. Code parsing the old lines must change.
- **A non-UTF8 Postgres database uses the `C` locale**, created from `template0`. Text sorts by byte
  value. `UTF8` databases are created exactly as before.
- **Postgres role passwords are sent as a SCRAM-SHA-256 verifier.** The server stores it whatever its
  `password_encryption` setting, so a server set to `md5` gets a SCRAM verifier for these roles; any
  client from PostgreSQL 10 on can log in with it. A password that SASLprep would rewrite (non-ASCII
  spaces, soft hyphens, or characters NFKC changes, such as fullwidth letters) is refused with
  `WeakPassword`, because the verifier would not match it.
- **`doctor` can now fail on a Postgres server**: for a superuser admin, an admin without `CREATEDB`
  or `CREATEROLE`, or a server older than 13. A superuser admin also raises `SuspiciousActivity`.
  `DoctorService` takes a `PostgresAdminCheck` as a new last constructor argument, resolved from the
  container.
- **`AdminConnection::run()` throws `LogicException` for a statement scoped to another database.**
  Engines in this package only scope Postgres statements. An engine of your own is affected only if
  it calls `Statement::inDatabase()`.

## From pre-release to 0.x

No published releases yet. This file will document breaking changes and
migration steps between released versions.

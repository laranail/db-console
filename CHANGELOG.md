# Changelog

All notable changes to `laranail/db-console` are documented in this file.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/)
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- **A hosting profile per server.** `servers.<name>.hosting` takes `self_hosted` (the default),
  `digitalocean`, `rds`, `cloud_sql` or `generic`. A managed hosting:
  - hides the provider's and the engine's own accounts from `AccountManager::list()`;
  - defaults a new MySQL-family account's host to `%` (`ServerRegistry::defaultAccountHost()`, used
    by `user:create`);
  - treats the provider's point-in-time recovery as the backup before a drop (see Changed).

  `ServerRegistry::capabilities()` merges the profile into the engine's `Capabilities`. An unknown
  value fails instead of becoming self-hosted. New: `Enums\Hosting`, `Domain\HostingProfile`.
- **Doctor checks what a managed server enforces** (`Doctor\HostingCheck`):
  - a self-hosted server whose host is DigitalOcean's or RDS's;
  - a MySQL admin that cannot read `mysql.user`;
  - `sql_require_primary_key` and `gtid_mode`;
  - a Postgres admin without `pg_signal_backend`, which a forced drop needs.
- **A live provisioning job against MySQL 8.4 and 9 and MariaDB 11.4 and latest** (`live-mysql` in
  `tests.yml`). 8.4 is the only MySQL DigitalOcean offers. It runs the `live-mysql` group as an
  admin holding exactly the grant doctor recommends, never root, with `sql_require_primary_key=ON`
  on MySQL: provision through the wizard with the default `utf8mb4_unicode_ci`, migrate and write as
  the new account, grant every preset privilege, revoke, move an account to a new host, drop an
  account and a database, check emptiness, and roll the wizard back. `MinimalAdminGrantTest` keeps
  the job's grant, the README and the recipe the same as `Doctor\MinimalAdminGrant`.
- `Doctor\MinimalAdminGrant::statements()`, the recommended admin per engine, and
  `MySqlEngine::grantablePrivileges()`, the privilege keywords it builds the grant from.
- **A live provisioning job against PostgreSQL 14 to 18** (`live-pgsql` in `tests.yml`), plus the
  19 release candidate as a leg whose failures do not fail the run. 18 is the latest release, 15
  to 17 the three before it, and 14 the oldest the project still supports. It runs the
  `live-pgsql` group as an admin holding only `CREATEDB CREATEROLE`, the shape of a managed
  service's admin user: provision, migrate as the new role, read a table created after the grant,
  drop the role while it still owns objects, drop a database with a session open, check
  emptiness, and fail a drop and a grant part way. The engine tests compare SQL strings, which is
  how every Postgres defect below passed.
- **Statements can be scoped to a database** (`Statement::inDatabase()`, `Statement::$database`),
  and `Servers\ScopedConnections` runs each one on a short-lived connection to its database, built
  from the admin connection's own configuration. `AdminConnection::run()` refuses a statement
  scoped to a database it is not on, so a grant can no longer land in the wrong database quietly.
- `Engines\OwnershipReleasingEngine`, `Engines\EmptinessProbingEngine` and
  `Engines\GrantVerifyingEngine`, implemented by `PostgresEngine`, following the `HostScopingEngine`
  pattern for dialect only one engine needs.
- `ScopedConnections::transaction()` runs work in a transaction on every connection it touches,
  through a `ScopedSession` that can checkpoint with `commit()`. For engines with transactional DDL.
- `Services\OwnershipReleasingDrop`, which drops a Postgres role so that a failure leaves as little
  behind as Postgres allows (see Fixed).
- The `grant.not_applied` exception code (`GrantNotApplied`, HTTP 502).
- **`doctor` checks a Postgres admin.** It skipped Postgres and reported every admin as fine, a
  superuser included. It now reads the role from `pg_roles` (`Engines\AdminInspectingEngine`,
  judged by `Doctor\PostgresAdminCheck`) and fails a superuser, an admin without `CREATEDB` or
  `CREATEROLE`, and a server older than 13, and warns when the admin connects through a known
  pooler port (6432, 6543, 25061).
- `Engines\DatabaseScopedGrantsEngine`, `Engines\ScramSha256` and `ScopedConnections::select()`,
  which reads rows from each statement's own database.

### Fixed

- **`MySqlEngine::createAccountWithAuth()` spliced its hash literal into `CREATE USER` unchecked.**
  It now refuses anything that is not a `0x` literal of whole hex bytes, as `MariaDbEngine` already
  did; the two share the check.
- **A MySQL database holding only a routine, an event or a trigger read as empty**, so a wizard
  rollback dropped it with them. The MySQL family now implements `EmptinessProbingEngine` and counts
  `TABLES`, `ROUTINES`, `EVENTS` and `TRIGGERS`.
- **Postgres grants covered schema `public` only.** Grants, revokes and the privilege check now
  cover every user schema the admin can grant on, and default privileges are database-wide (no
  `IN SCHEMA`) with `ON SCHEMAS`, so schemas and objects created later are covered. Revokes also
  clear the per-schema defaults earlier releases set. A schema owned by a superuser is skipped.
- **A Postgres reader missed tables other roles created later.** A default privilege belongs to the
  role that creates the object. A grant now sets defaults `FOR ROLE` each role that can create
  objects in the database. A writer granted after the readers inherits the admin's database-wide
  defaults, which list every role db-console granted there.
- **Granting on objects another role owns failed or did nothing.** `RoleMembership` takes membership
  in the roles `OwnerDelegatingEngine::rolesToAssume()` lists for the duration of the grant or
  revoke, and gives it back afterwards. A role the admin cannot take is reported in the result
  (`not_covered`) and logged.
- **A Postgres database with an application role's session open could not be dropped.**
  `WITH (FORCE)` ends only sessions the admin may end, and a CREATEROLE admin on PG16+ does not
  inherit the roles it creates. The drop now reads whose sessions they are (`SessionEndingEngine`),
  takes membership so `FORCE` can end them, and refuses up front, before the backup runs, when a
  superuser or a role the admin cannot act as holds one.
- **A failed backup-before-drop still dropped the database.** `DbToolsBackupService` logged the
  failure and returned, so the drop it was meant to make recoverable ran anyway. It now throws the new
  `BackupFailed` (code `backup.failed`), the drop does not run, and the failure is audited like any
  other failed drop. `backup.abort_drop_on_failure=false` restores the old log-and-drop.
- **Every backup of a database overwrote the last, and PostgreSQL archives were named `.sql`.**
  Snapshots are now `{database}-{UTC timestamp}.dump` (PostgreSQL) or `.sql`, so a restore picks
  `pg_restore` for a custom-format archive instead of replaying it through psql.
- **A `DATABASE_URL` admin connection backed up the url's database, not the target.** The url is now
  expanded before the target database is set, so it can no longer override it.
- **`backup.retention_days` was never read.** Snapshots of a database older than it are now pruned
  after each successful snapshot; `0` keeps them all.
- **A non-local backup disk dumped into the working directory.** `path()` on S3 returns the object
  key. The snapshot now fails, with the disk and driver named, unless the disk uses the `local` driver.

- **The `tls` block was never applied, and `doctor` reported TLS from config alone.** Nothing turned
  `tls.ca/cert/key/verify` into driver options, so an admin connection could run in plaintext while
  `TlsChecker` reported `required_ok` because `tls.enabled` was true. `ServerRegistry` now writes the
  block into the admin connection before connecting (PDO SSL options for MySQL/MariaDB; `sslmode`,
  `sslrootcert`, `sslcert`, `sslkey` for PostgreSQL; `encrypt`, `trust_server_certificate` for SQL
  Server), purging a connection already opened without it. `TlsChecker` asks the live session
  through the new `TlsProbe` contract (default `SessionTlsProbe`), so a plaintext session is `off`
  and fails `doctor` whatever the config says. A new `TlsStatus::Unmeasured` case covers TLS that
  is configured but could not be checked.

  **Behaviour change:** `TlsChecker::status()` now opens the admin connection to measure it, where
  it used to read config only; `encryption:status` therefore needs the server reachable to report
  anything but `unmeasured`. A PostgreSQL server without SSL now refuses a connection with
  `tls.enabled=true` (`sslmode=require`) instead of falling back to plaintext.

- **`TlsChecker::isLocal()` treated a connection configured only by `url` (`DATABASE_URL`) as
  localhost**, which exempted exactly the managed-database setups from the TLS check. It now parses
  the url and checks every `read`/`write` host.
- **The recommended MySQL/MariaDB admin could not grant the `app_standard` preset**, nor `full`:
  `GRANT` needs `GRANT OPTION` on each privilege it hands out, and the recommendation left out
  `CREATE TEMPORARY TABLES`, `LOCK TABLES`, `REFERENCES`, `CREATE ROUTINE`, `ALTER ROUTINE`, `EVENT`
  and `TRIGGER`, so provisioning failed at its grant step with `InsufficientPrivilege`. The
  recommendation now gives every privilege a preset can carry `WITH GRANT OPTION`, built from the
  engine's own privilege map, and drops `RELOAD`, which nothing db-console runs uses.
- **Changing an account's host failed on MariaDB.** The account is recreated from its stored hash
  with `IDENTIFIED WITH <plugin> AS 0x...`, and MariaDB's grammar takes only a quoted string after
  `AS`. `MariaDbEngine` now decodes the hash and quotes it, and refuses one that is not hex.
- **A failed provisioning wizard dropped an account or database it had not created.** The wizard
  adopts an account or database that already exists, and its rollback then dropped it with the rest
  -- a customer's existing login, if a later step failed. The rollback now undoes only what the run
  created, as its documentation said. This applies to every engine.
- **PostgreSQL could not create a database with the package's own default charset.** `utf8mb4`
  became `ENCODING 'UTF8MB4'`, which Postgres has no encoding for. `PostgresEngine` now maps MySQL
  charset names to Postgres encodings (`utf8mb4`/`utf8mb3` → `UTF8`, `ascii` → `SQL_ASCII`), so the
  wizard, CLI, API and WebUI defaults all work without each call site knowing the engine.
- **PostgreSQL grants landed in the admin's database, not the target.** `GRANT ... ON ALL TABLES IN
  SCHEMA public` acts on the connected database, which was the admin connection's, so the target
  received `CONNECT` and nothing else. Schema, table, sequence and function grants now run inside the
  target database, with `GRANT USAGE ON SCHEMA public` (and `CREATE` when the set has it: PG15
  revoked it from `PUBLIC`) and `ALTER DEFAULT PRIVILEGES`, so tables the admin creates later are
  covered too.
- **PostgreSQL put `CREATE` and `EXECUTE` in a table grant, and turned any privilege it did not
  recognise into `SELECT`.** Each privilege now goes on the object Postgres grants it on: `CREATE`
  on the schema, `EXECUTE` on functions, `CREATE TEMPORARY TABLES` as `TEMPORARY` on the database,
  and sequence `USAGE`/`SELECT` alongside `INSERT`/`UPDATE`/`SELECT`. `ALTER`, `DROP`, `INDEX`,
  `ALTER ROUTINE`, `LOCK TABLES` and `EVENT` grant nothing, since Postgres reserves the first four
  to an object's owner. An unclassified privilege throws `UnknownPrivilege`.
- **PostgreSQL drops failed whenever anything was attached.** `DROP DATABASE` now uses `WITH
  (FORCE)`, which ends the sessions the admin may terminate; one held by a role it cannot signal
  still blocks the drop. `DROP ROLE` is preceded by
  `REASSIGN OWNED BY ... TO CURRENT_USER` and `DROP OWNED BY ...` inside every database the role has
  objects or privileges in, read from `pg_shdepend`, after the admin takes membership in the role.
  A CREATEROLE admin on PG16+ does not inherit the roles it creates.
- **A Postgres role drop that failed part way left the admin a member of the role**, with its
  privileges, and could leave the role's objects in some databases reassigned. The drop now refuses
  before changing anything when a database the role has objects in is out of the admin's reach;
  takes membership only when the admin does not already have the role's privileges; releases
  ownership in one transaction per database and drops the role in another; and gives the
  membership back when a step fails, escalating as `RollbackFailed` if that fails too.
- **A Postgres grant could report success without granting.** When the admin holds a privilege
  without the grant option, as on a database or schema another role owns, `GRANT` warns, grants
  what it can and succeeds. A grant now runs in one transaction on the target database together
  with a check of the role's effective privileges, and anything missing rolls the whole grant back
  and throws `GrantNotApplied`, naming each privilege. Revokes run in one transaction too.
- **A drop whose emptiness check failed was not audited.** The check now runs inside the drop's
  failure handling, so it is logged and dispatches `OperationFailed` like any other failed drop.
- **PostgreSQL showed a role's grants in the admin's own database only.** `showGrants()` read
  `information_schema.role_table_grants` on the admin connection, so a role provisioned into any
  other database showed nothing, and schema and database grants never showed at all. Database
  grants are now read from `pg_database`, and schema, table, sequence, function and default-privilege
  grants inside every database the role has entries in. Each line is `<kind> <database> <object>
  <privilege>`; a database the admin cannot connect to is listed as not readable.
- **PostgreSQL could not create a `LATIN1` or `SQL_ASCII` database**, the two non-UTF8 encodings it
  offers: a copy of `template1` keeps its `UTF8` encoding. Any encoding other than `UTF8` now copies
  `template0` with the `C` locale. The `UTF8` path is unchanged.
- **PostgreSQL role passwords were sent in clear**, where `log_statement = 'ddl'` or a failed
  statement could write them to the server log. `CREATE ROLE` and `ALTER ROLE` now send a
  SCRAM-SHA-256 verifier computed client-side, which Postgres stores as given.
- **PostgreSQL's "is empty" check always answered "not empty".** It is now read inside the target
  database, so a wizard rollback drops the database it created instead of leaving it behind, and an
  empty database is no longer backed up before a drop.

- **`audit:view --limit=<non-numeric>` printed nothing.** `(int)` on a typo is `0`, and `limit(0)`
  returns no rows -- so a mistyped limit rendered an empty audit view, which reads exactly like
  "there is no audit history" rather than like a bad argument. Now `intOption('limit', 25)`, which
  falls back to the documented default.

- **A repeatable option containing an empty entry carried it through.** `--db=a --db=` produced
  `['a', '']`, and the empty string was passed on as a database, user, role, ability or event name.
  The six `(array)` casts are now `arrayOption()`, which trims and drops empties.

- **`docs/tools/commands.md` documented a `db-console:<command>` alias for every command, and
  `docs/tools/api.md` named the API middleware `db-console.api-guard`.** No command declares an
  alias, and the middleware is `laranail-db-console.api-guard`.

### Changed

- `laranail/db-tools` (dev, suggested) is required at `^0.1.6`, whose backups honour `url`, pass TLS
  to the dump client and take a consistent dump on managed servers.
- The `live-mysql` job runs MySQL with GTID on, as DigitalOcean does, installs each family's own dump
  client, and drops a non-empty database with backups on as the recommended admin, then restores the
  dump.
- On a server with a managed hosting, the drop no longer dumps the database first. It logs
  `database.backup.provider` with a `restore_point` timestamp to restore from instead.
  `backup.dump_on_managed=true` (env `DB_CONSOLE_BACKUP_DUMP_ON_MANAGED`) keeps dumping.
  Self-hosted servers, the default, are unaffected.

- `laravel/framework ^13.0` is now declared in `require`. `src/` uses `Dispatchable`, `FormRequest` from `Illuminate\Foundation`, which no `illuminate/*` component ships, so the dependency only arrived through the host application.
- The base `DBConsoleCommand` applies `laranail/package-tools`' `Commands\Concerns\ReadsOptions`;
  its own `--server` resolution and `ServerAddCommand`'s `--host`/`--port` handling were the same
  normalisation written out by hand and now use the accessors.

- **`DbCreateCommand` no longer declares a private `stringOption()`.** It was the trait's method
  reimplemented, and once the base applied the trait PHP refused the class outright -- a private
  method cannot narrow a protected one. Removing it is behaviour-preserving: both return `''` for
  a non-string value.

- **The gate abilities and the install command are vendor-scoped.** The 21 abilities are
  `laranail-db-console.<permission>` (was `db-console.<permission>`), and the install command is
  `laranail::db-console.install` (was `db-console:install`). Both lived in flat, host-owned
  registries, where a sibling package or the application claiming the same key silently replaces
  it. `ConsolePermission::ability()` returns the scoped name, so the audit trail records it as the
  target of a denied action, and `install` seeds permission rows under it. Rows stored before the
  rename keep their names and still resolve. Requires `laranail/package-tools ^0.1.3`.

### Added

- **`ConsolePermission::fromAbility()`** reads a gate ability or stored permission name in either
  form, and **`deprecatedAbility()`** names the pre-0.1 ability. Both RBAC drivers read stored names
  through `fromAbility()`.
- **`tests/Feature/NamingConventionTest.php`** reads the live gate, Artisan and middleware
  registries through package-tools' `AssertsRegisteredNames`, and checks every deprecated alias
  answers as its replacement and announces itself once.

- **`assertNoNullOnlyOptionGuards()` is enforced over `src/`.**

### Deprecated

- **Gate abilities `db-console.<permission>` (21).** Each is still defined and asks the gate for its
  `laranail-db-console.*` ability, so a host's definition or before/after callback for the scoped
  name governs the old one too. The first check of each raises one `E_USER_DEPRECATED` notice.
- **Command `db-console:install`.** Still registered, hidden; it prints one line naming
  `laranail::db-console.install`, then runs it and returns its exit code.

Both are removed no earlier than the next minor after 0.1.

## [0.1.0] - 2026-07-11

Initial public release.

[Unreleased]: https://github.com/laranail/db-console/compare/v0.1.0...HEAD

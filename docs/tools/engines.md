# Engines

The engine layer is the only place SQL is built.

## Overview

Five engines implement one interface: `MySqlEngine`, `MariaDbEngine`, `PostgresEngine`, `SqlServerEngine`, `SqliteEngine`, resolved by `EngineFactory` from a server's declared engine type. Each turns validated value objects into exact statements through a `Quoter` (backtick, double-quote, or bracket) and declares its honest `Capabilities` — what it can and cannot do.

## The contract

An engine builds statements for creating and dropping databases and accounts, granting and revoking privileges, and reading grants. It never runs them — the `AdminConnection` does that. Because engines are the sole SQL producers, an architecture test forbids any other file from minting a `Statement`, and it must never be weakened.

## Honest capability degradation

SQLite has no account or privilege concept, so its capabilities report `false` and every account/grant call throws `UnsupportedOperation` rather than pretending. PostgreSQL scopes accounts through `pg_hba`, not DBConsole, so it reports that in its capability note. The UI and CLI only offer what the target actually supports.

## Statements scoped to a database

A statement can name the database it must run in (`Statement::inDatabase()`). An unscoped statement runs on the server's admin connection. A scoped one runs on a short-lived connection to its database, opened by `ScopedConnections` with the admin connection's own host, credentials and TLS options, and closed straight after. `AdminConnection` refuses a statement scoped to a database it is not on, so one sent down the wrong path fails instead of acting on the wrong database.

Only `PostgresEngine` scopes statements, because Postgres schema grants, default privileges and `REASSIGN OWNED` act on the connected database.

## MySQL and MariaDB

A database is empty only when `information_schema` holds no table or view, routine, event or trigger for it (`EmptinessProbingEngine`).

Both engines grant on `` `db`.* `` and scope accounts by host, `'user'@'host'`. A host change
recreates the account at the new host from its stored authentication, read from `mysql.user` as a hex
literal so the password is never known. MySQL takes the hash as `AS 0x...`, which a binary
`caching_sha2_password` hash needs; MariaDB's grammar takes only a quoted string after `AS`, so
`MariaDbEngine` decodes the hex and quotes it. Both refuse a hash literal that is not whole bytes of
hex, since it is spliced into the statement.

These paths are tested against MySQL 8.4 and 9 and MariaDB 11.4 and latest by the `live-mysql` CI
job, as an admin holding exactly the grant `doctor` recommends (`Doctor\MinimalAdminGrant`), with
`sql_require_primary_key=ON` on MySQL. The job also takes a backup before a drop and restores it, as
that admin with GTID on: db-tools dumps with `--single-transaction --no-tablespaces`, which needs no
`PROCESS`, `LOCK TABLES` or `RELOAD`.

## PostgreSQL

Grants for a database run inside it:

| Privilege | Granted as |
|---|---|
| any set | `CONNECT` on the database, `USAGE` on schema `public` |
| `select` | `SELECT` on tables and sequences |
| `show_view` | `SELECT` on tables |
| `insert`, `update` | the table privilege, plus `USAGE` on sequences |
| `delete`, `references`, `trigger` | the table privilege |
| `create`, `create_view`, `create_routine` | `CREATE` on schema `public` |
| `create_temporary_tables` | `TEMPORARY` on the database |
| `execute` | `EXECUTE` on functions |
| `alter`, `drop`, `index`, `alter_routine` | nothing: Postgres reserves them to the owner, and the role owns the tables it creates |
| `lock_tables`, `event` | nothing: implied by table privileges / no counterpart |

Each schema-wide grant comes with `ALTER DEFAULT PRIVILEGES IN SCHEMA public`, so tables the admin creates later are covered too; default privileges are per creating role, so tables another role creates are not. A privilege with no entry throws `UnknownPrivilege`.

Revoking removes the Postgres privilege a set maps to, even when another abstract privilege maps to the same one: revoking `show_view` removes `SELECT`. This fails closed rather than leaving access behind.

Charset names from MySQL are mapped to encodings: `utf8mb4` and `utf8mb3` to `UTF8`, `ascii` to `SQL_ASCII`, `latin1` to `LATIN1`.

Postgres collates by locale. A collation of `C` or `POSIX` (`Enums\Collation::C`, offered with `sql_ascii`) creates the database in that locale, `UTF8` included. The package's other collation names are MySQL's (`utf8mb4_unicode_ci` is the default), which have no meaning here and leave the locale to the encoding. Any other collation is refused with `UnsupportedOperation` rather than ignored.

A `UTF8` database is created from the default template, as before. Any other encoding is created `TEMPLATE template0 ... LC_COLLATE 'C' LC_CTYPE 'C'`: a copy of `template1` keeps its encoding, which is `UTF8` on every official image and managed service, so `CREATE DATABASE ... ENCODING 'LATIN1'` alone fails with "incompatible with the encoding of the template database". Only `template0` may be copied into another encoding, and the cluster's locale (`en_US.utf8` and the like) is valid for `UTF8` only, where `C` is valid for every encoding. Text in those databases sorts by byte value. `C` is a libc locale, and on a cluster whose default locale provider is ICU (`initdb --locale-provider=icu`, PostgreSQL 15 and later) `template0` is ICU, which has no `SQL_ASCII`; so from 15 the statement adds `LOCALE_PROVIDER libc`. That option is a syntax error before 15, so `DatabaseManager::create()` reads `server_version_num` first and asks for an engine built for that version (`ServerVersionAwareEngine`). The `live-pgsql` job runs one PostgreSQL 17 leg on an ICU cluster.

Passwords are sent as a SCRAM-SHA-256 verifier, never in clear: `CREATE ROLE ... PASSWORD 'SCRAM-SHA-256$4096:<salt>$<StoredKey>:<ServerKey>'`, computed client-side by `Engines\ScramSha256` (RFC 5802, RFC 7677) with 16 random bytes of salt. Postgres stores a verifier it is given as-is, so the password never appears in the statement text, which `log_statement = 'ddl'` and a failed statement (`log_min_error_statement`) write to the server log. The redacted form is unchanged. Postgres runs SASLprep on a password before hashing it; the verifier matches whenever SASLprep leaves the password unchanged (ASCII, not valid UTF-8, or already in NFKC form with no non-ASCII spaces or soft hyphens), and a password SASLprep would rewrite is refused with `WeakPassword` rather than stored as a verifier no login matches.

Reading a role's grants (`PrivilegeManager::grants()`, and `showGrants()` for one line each) goes through `DatabaseScopedGrantsEngine`. Grants on databases are read from `pg_database`, which is shared; grants on schemas, tables, views, sequences, functions and default privileges live in each database's own catalog, so they are read inside every database the role has entries in (the same `pg_shdepend` lookup a drop uses) and in the admin's own.

The listing is of effective privileges, each with where it comes from. An object whose ACL was never set has the default one, which gives its owner every privilege; `acldefault()` spells it out, so a role's own tables are listed. Entries held through a role it inherits from (`pg_has_role(..., 'USAGE')`) and through `PUBLIC` are listed too. Each line is `<kind> <database> <object> <privilege>`, then `via owner`, `via PUBLIC` or `via <role>` unless the privilege is granted to the role itself: `table shop public.orders SELECT`, `table shop public.orders DELETE via owner`, `schema shop public USAGE via PUBLIC`. Template databases and the system schemas (`pg_catalog`, `information_schema`, `pg_*`) are left out, since PUBLIC's access there says nothing about the role. A default-privileges line names the role whose new objects it covers: `default privileges shop tables for role db_console_admin SELECT`. Object-level entries are read only in databases the role has entries of its own in: what it reaches in other databases through `PUBLIC` alone shows as the database's `CONNECT via PUBLIC`. A superuser holds every privilege without ACL entries and is not expanded. A database the admin may not connect to is listed as not readable rather than left out.

The same listing is `laranail::db-console.user:grants --user=<name> [--host=%] [--direct] [--json]` and `GET {api.path}/servers/{server}/accounts/{username}/grants?host=%25&direct=1`; `--direct` and `direct=1` keep what the account holds itself and as an owner.

A grant runs in one transaction on the target database, followed by a check of the role's effective privileges (`has_*_privilege`). Postgres answers a `GRANT` the admin holds no grant option for with a warning, grants what it can and succeeds; the check catches that, rolls the whole grant back and throws `GrantNotApplied` naming each missing privilege. It counts privileges held through PUBLIC or ownership, so on PostgreSQL 14 and earlier, where PUBLIC holds `CREATE` on schema `public`, a role always has it.

`DROP DATABASE` runs `WITH (FORCE)`, which ends the sessions the admin may terminate. `OwnershipReleasingDrop` drops a role in five steps:

1. Read the databases the role has objects or privileges in from `pg_shdepend`, and refuse with `InsufficientPrivilege` before changing anything if the admin cannot connect to one.
2. Take membership in the role (`GRANT role TO CURRENT_USER`), unless the admin already has its privileges. `REASSIGN OWNED` and `DROP OWNED` need them, and on PostgreSQL 16 and later a `CREATEROLE` admin does not inherit the roles it creates.
3. `REASSIGN OWNED BY ... TO CURRENT_USER` and `DROP OWNED BY ...` inside each of those databases, one transaction each, committed together.
4. The same on the admin's own database, then `DROP ROLE`, in one transaction. It cannot share step 3's transactions: `DROP ROLE` sees dependencies another session has not committed.
5. On a failure in step 3 or 4, give the membership back; if that fails too, throw `RollbackFailed`.

Only a failure in step 4 after step 3 committed leaves a partial result: the role's objects in the other databases then belong to the admin.

The emptiness check before a drop or a wizard rollback counts relations inside the database itself.

These paths are tested against PostgreSQL 14 to 18, and the next major's release candidate, by the `live-pgsql` CI job, as an admin with only `CREATEDB CREATEROLE`.


### Schemas, owners and default privileges

Grants cover every user schema the admin can grant on: `public`, and any schema whose owner's privileges the admin has. A schema a superuser owns is skipped. Default privileges are database-wide (`ALTER DEFAULT PRIVILEGES` without `IN SCHEMA`), plus `ON SCHEMAS`, so schemas and objects created later are covered.

Two rules of Postgres shape the rest:

- **Only an object's owner, or a role with the owner's privileges, may grant on it.** Before a grant or revoke, `RoleMembership` takes membership in the roles `OwnerDelegatingEngine::rolesToAssume()` lists (owners of schemas, tables, sequences and functions, and roles that can create objects), and gives it back afterwards. A role it cannot take is reported as `not_covered`.
- **A default privilege belongs to the role that creates the object.** A grant sets defaults `FOR ROLE` each role that can create objects in the database, so a reader sees what the application's own role creates in a later migration. A writer granted after the readers inherits the admin's database-wide defaults, which list every role db-console granted there.

### Dropping a database with sessions open

`DROP DATABASE ... WITH (FORCE)` ends only the sessions the admin may end. `SessionEndingEngine::sessionRoles()` lists whose sessions they are. The drop takes membership in roles the admin can act as, so `FORCE` can end their sessions, and refuses with `InsufficientPrivilege` before anything runs when a superuser, or a role it cannot act as, holds one.

On a managed server, `doctor`'s `server:<name>:forced-drop` check tells you whether the admin can end other roles' sessions at all; see [Managed databases](../managed-databases.md).

---

[← Docs index](../../README.md#documentation)

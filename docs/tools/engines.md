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

Charset names from MySQL are mapped to encodings: `utf8mb4` and `utf8mb3` to `UTF8`, `ascii` to `SQL_ASCII`. The collation is not used.

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

---

[← Docs index](../../README.md#documentation)

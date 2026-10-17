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

`DROP DATABASE` runs `WITH (FORCE)`, which ends the sessions the admin may terminate. Dropping a role first takes membership in it, then runs `REASSIGN OWNED BY ... TO CURRENT_USER` and `DROP OWNED BY ...` in every database the role has objects or privileges in, read from `pg_shdepend`. The emptiness check before a drop or a wizard rollback counts relations inside the database itself.

These paths are tested against PostgreSQL 18 by the `live-pgsql` CI job, as an admin with only `CREATEDB CREATEROLE`.

---

[← Docs index](../../README.md#documentation)

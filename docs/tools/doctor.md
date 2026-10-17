# doctor

Health-check every server and report the security posture.

## Overview

`doctor` probes each registered server for its [SSH tunnel](ssh-tunnels.md#doctor) when it has one, reachability, TLS posture, the real capability set, and — the doctrine-not-option check — whether the admin account is **root-like** (`ALL PRIVILEGES ON *.*` or `SUPER`), in which case it fails with the exact minimal-grant fix so setup cannot quietly proceed insecure. It also reports the active secret driver (warning on `app_key`) and the catalog encryption mode. A root-like admin raises a `SuspiciousActivity` alert. `doctor` runs as the last step of install.

## Operator assurance

| Check | Severity | When |
|---|---|---|
| `security:assurance` | error | enforcement is `off` in production |
| `security:assurance` | warning | `off` outside production, or `report` in production |
| `security:assurance-binding` | warning | the host has bound no `OperatorAssurance`, so `NullOperatorAssurance` answers |
| `security:owners` | warning | with enforcement on, no global Owner has a second factor |

See [Operator assurance](operator-assurance.md).

## PostgreSQL

Postgres has no `SHOW GRANTS`, so `doctor` reads the admin role's attributes from `pg_roles` instead, through `AdminInspectingEngine::adminProfile()`, and judges them in `Doctor\PostgresAdminCheck`:

| Check | Severity | When |
|---|---|---|
| `server:<name>:admin` | error | the admin is a superuser, which bypasses every privilege check; raises `SuspiciousActivity` like a root-like MySQL admin |
| `server:<name>:admin-privileges` | error | the admin lacks `CREATEDB` or `CREATEROLE`, which provisioning needs |
| `server:<name>:version` | error | the server is older than PostgreSQL 13, which `DROP DATABASE ... WITH (FORCE)` needs |
| `server:<name>:pooler` | warning | the admin connection uses a known pooler port, 6432 (PgBouncer), 6543 (Supabase) or 25061 (DigitalOcean connection pools), and the server itself does not listen on it |
| `server:<name>:pooler` | info | the connection is relayed from another port: the configured port differs from the one the server listens on (a pooler, a proxy or a port mapping) |
| `server:<name>:role-admin` | warning | PostgreSQL 16 or later, and the admin holds no ADMIN OPTION on some login roles, which it then cannot drop, rename or change the password of; names up to ten |

A transaction-mode pooler hands each statement to whichever server session is free, which a grant's transaction and the per-database connections a grant or drop opens do not survive, and some poolers refuse `CREATE DATABASE` and `DROP DATABASE`. Point the admin connection at the server directly. The check compares the configured port with `inet_server_port()`, the port the server itself listens on: a known pooler port the server listens on is a direct connection and is not warned about, and a pooler on any other port shows as a relayed connection. A port mapping (a container publishing 5432 as 54320) shows the same way, which is why that finding is informational. Over a Unix socket the server reports no port and nothing is said.

From PostgreSQL 16, `CREATEROLE` covers only the roles the admin holds ADMIN OPTION on, which by default are the roles it created. `doctor` lists the other login roles (not superusers, not `pg_*`, not the admin) through `AdminInspectingEngine::unmanageableRoles()`, so a role DBConsole should manage can be handed over with `GRANT <role> TO <admin> WITH ADMIN OPTION` before a drop or a password rotation fails on it. Before 16 a `CREATEROLE` admin manages every role that is not a superuser, and nothing is said.

## Hosting

Every server also gets the checks in `Doctor\HostingCheck`, judged from what the admin can see:

| Check | Severity | When |
|---|---|---|
| `server:<name>:hosting` | warning | the server is `self_hosted` but its host belongs to DigitalOcean or RDS; the fix names the `hosting` value to set |
| `server:<name>:accounts` | warning | MySQL family: the admin cannot read `mysql.user`, so listing accounts and changing an account's host fail |
| `server:<name>:server-settings` | info | MySQL family: reports `sql_require_primary_key` and `gtid_mode`, which a managed MySQL turns on |
| `server:<name>:forced-drop` | warning | PostgreSQL: the admin is not a superuser and not a member of `pg_signal_backend`, so dropping a database another role is connected to fails |

The `capabilities` finding reads `ServerRegistry::capabilities()`, so it includes `provider_backups` on a managed server.

---

[← Docs index](../../README.md#documentation)

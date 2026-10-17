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
| `server:<name>:pooler` | warning | the admin connection uses a known pooler port: 6432 (PgBouncer), 6543 (Supabase) or 25061 (DigitalOcean connection pools) |

A transaction-mode pooler hands each statement to whichever server session is free, which a grant's transaction and the per-database connections a grant or drop opens do not survive, and some poolers refuse `CREATE DATABASE` and `DROP DATABASE`. Point the admin connection at the server directly. The port check is a heuristic: a pooler on another port is not detected, and a direct connection on one of these ports is warned about.

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

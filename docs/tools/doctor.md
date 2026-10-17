# doctor

Health-check every server and report the security posture.

## Overview

`doctor` probes each registered server for reachability, TLS posture, the real capability set, and — the doctrine-not-option check — whether the admin account is **root-like** (`ALL PRIVILEGES ON *.*` or `SUPER`), in which case it fails with the exact minimal-grant fix so setup cannot quietly proceed insecure. It also reports the active secret driver (warning on `app_key`) and the catalog encryption mode. A root-like admin raises a `SuspiciousActivity` alert. `doctor` runs as the last step of install.

## PostgreSQL

Postgres has no `SHOW GRANTS`, so `doctor` reads the admin role's attributes from `pg_roles` instead, through `AdminInspectingEngine::adminProfile()`, and judges them in `Doctor\PostgresAdminCheck`:

| Check | Severity | When |
|---|---|---|
| `server:<name>:admin` | error | the admin is a superuser, which bypasses every privilege check; raises `SuspiciousActivity` like a root-like MySQL admin |
| `server:<name>:admin-privileges` | error | the admin lacks `CREATEDB` or `CREATEROLE`, which provisioning needs |
| `server:<name>:version` | error | the server is older than PostgreSQL 13, which `DROP DATABASE ... WITH (FORCE)` needs |
| `server:<name>:pooler` | warning | the admin connection uses a known pooler port: 6432 (PgBouncer), 6543 (Supabase) or 25061 (DigitalOcean connection pools) |

A transaction-mode pooler hands each statement to whichever server session is free, which a grant's transaction and the per-database connections a grant or drop opens do not survive, and some poolers refuse `CREATE DATABASE` and `DROP DATABASE`. Point the admin connection at the server directly. The port check is a heuristic: a pooler on another port is not detected, and a direct connection on one of these ports is warned about.

---

[← Docs index](../../README.md#documentation)

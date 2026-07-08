# Installation

Requirements, install, the minimal admin account, and catalog setup for `laranail/db-console`.

## Requirements

- PHP `^8.4.1 || ^8.5`
- Laravel `^13.0`
- A reachable database server (MySQL 8+, MariaDB 11+, PostgreSQL 16+, SQL Server 2022, or SQLite) with a **minimal** admin account
- Optional: `laravel/sanctum` or `laravel/passport` (REST API), `spatie/laravel-permission` (Spatie RBAC driver), `laranail/database-tools` (backup-before-drop, inspection), a KMS/Vault SDK (production secret drivers)

## Install

```bash
composer require laranail/db-console
php artisan db-console:install
```

The install flow publishes the config and language files, runs the catalog migrations, seeds the shipped console roles, assigns the bootstrap Owner, and runs `doctor`.

## The minimal admin account

DBConsole must never be given a root account. Provision a dedicated, least-privilege admin — `doctor` fails if the account is root-like (has `ALL PRIVILEGES ON *.*` or `SUPER`). See [Provision a minimal admin per engine](recipes/provision-minimal-admin.md) for the exact statements per engine.

## The catalog

DBConsole keeps a small record of what it manages in a dedicated `db_console_catalog` connection (SQLite by default), separate from your app database. Reads are always live from the managed server; the catalog is history. It can be encrypted at the column level (default) or whole-file with SQLCipher — see [Catalog encryption](tools/catalog-encryption.md).

---

[← Docs index](../README.md#documentation)

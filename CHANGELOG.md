# Changelog

All notable changes to `laranail/db-console` are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [0.1.0] - 2026-07-08

Initial release.

### Added

- Multi-server database, account, and privilege management for MySQL, MariaDB, PostgreSQL, SQL Server, and SQLite, headless by design.
- Shared allow-list validation layer (value objects + Rules + FormRequests + `RuleProvider`) consumed identically by the CLI, REST API, and web UI. Injection barriers are covered by a corpus test that must never be weakened.
- Engine layer as the sole SQL producer, per-dialect with a `Quoter` and honest capability degradation (SQLite has no accounts; PostgreSQL scopes accounts via `pg_hba`). Enforced by an architecture test.
- Dedicated admin connection per server; admin work never rides the app's default connection. TLS enforcement with `doctor` errors on non-local servers without TLS.
- Host-agnostic catalog: DBConsole's own records ride your app's default database connection by default (zero infrastructure); a dedicated, isolated catalog — column-encrypted, optionally whole-file SQLCipher — remains opt-in. Reads are live; the catalog is history.
- Secret vault with four drivers (app-key, KMS, HashiCorp Vault, reference); secrets redact themselves in logs, exceptions, audit, and webhooks; `secrets:rotate`.
- Services: `DatabaseManager`, `AccountManager`, `PrivilegeManager`, provisioning wizard with compensating rollback, attach/detach batches, grant-preserving host change, and `reconcile` (report-only, never auto-mutates the server).
- Deny-by-default, scope-aware RBAC (global ⊇ server ⊇ database) with builtin and Spatie drivers returning identical verdicts; shipped roles seeded on install.
- Append-only, hash-chained audit trail with `audit:verify`; notifications and severity-based alerts; backup-before-drop via `laranail/db-tools`.
- Full Artisan surface (`laranail::db-console.*` with `db-console:*` aliases), `db-console:install`, and `doctor` (reachability, TLS, capabilities, root-like-admin detection with the exact minimal-grant fix).
- Optional, off-by-default REST API (Sanctum/Passport guard, IP allow-list, HTTPS, body confirmation on destructive actions; token abilities capped at the operator's own) and HMAC-signed, secret-free webhooks with retry/backoff and auto-disable.
- English language files for validation, exceptions, commands, notifications, and enum labels; namespaced `laranail.db-console.*` configuration.

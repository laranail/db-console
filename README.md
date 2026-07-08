# laranail/db-console

[![Packagist Version](https://img.shields.io/packagist/v/laranail/db-console.svg?style=flat-square)](https://packagist.org/packages/laranail/db-console)
[![Tests](https://img.shields.io/github/actions/workflow/status/laranail/db-console/tests.yml?branch=main&label=tests&style=flat-square)](https://github.com/laranail/db-console/actions/workflows/tests.yml)
[![Static analysis](https://img.shields.io/github/actions/workflow/status/laranail/db-console/static-analysis.yml?branch=main&label=static%20analysis&style=flat-square)](https://github.com/laranail/db-console/actions/workflows/static-analysis.yml)
[![License MIT](https://img.shields.io/packagist/l/laranail/db-console.svg?style=flat-square)](LICENSE)

> Self-hosted, multi-server database, account, and privilege management for Laravel — guided, auditable flows over MySQL, MariaDB, PostgreSQL, SQL Server, and SQLite, with scoped RBAC, an encrypted catalog, a full CLI, and an optional REST API and webhooks.

Requires PHP `^8.4.1 || ^8.5` and Laravel `^13.0`. Headless by design: all logic, no UI (the [`laranail/db-console-webui`](https://github.com/laranail/db-console-webui) package is the thin Livewire/Flux front end). It uses your app's existing database connections — no separate infrastructure to stand up.

`db-console` is the safe way to run the operations a DBA does by hand — create a database, mint a least-privilege account, grant exactly the rights an app needs, rotate a credential, move a user to a new host — from Laravel, over any number of servers, without ever handing the tool a root account. Every input is allow-list validated, only the engine layer builds SQL (so injection has nowhere to land), secrets redact themselves, privileges are capped below server-wide, and every action lands in a tamper-evident audit trail.

## Install

```bash
composer require laranail/db-console
php artisan db-console:install
```

The installer publishes the config, runs the catalog migrations, seeds the shipped console roles, assigns the bootstrap Owner (set `DB_CONSOLE_OWNER_USER_ID`), and runs `doctor` to health-check your servers.

Point it at a **minimal** admin account, never root:

```sql
CREATE USER 'db_console_admin'@'%' IDENTIFIED BY '…';
GRANT CREATE, DROP, ALTER, INDEX, REFERENCES, CREATE USER, RELOAD ON *.* TO 'db_console_admin'@'%';
GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, DROP, ALTER, INDEX, EXECUTE, CREATE VIEW, SHOW VIEW
  ON *.* TO 'db_console_admin'@'%' WITH GRANT OPTION;
```

`doctor` fails loudly if you point it at a root-like account.

## Quick start

Provision a database, a least-privilege account, and its grant in one rollback-safe flow:

```bash
php artisan laranail::db-console.wizard \
  --server=primary --db=shop_prod --user=shop_app --host=% \
  --preset=app_standard --generate
```

Or drive the services directly from your own code — the same services the CLI, the REST API, and the web UI all call:

```php
use Simtabi\Laranail\DBConsole\Services\DatabaseManager;
use Simtabi\Laranail\DBConsole\Domain\{DbName, Charset};

app(DatabaseManager::class)->create('primary', new DbName('shop_prod'), new Charset('utf8mb4'));
```

## Mental model

| Concept | What it is |
|---|---|
| **Server** | A registered target (config- or catalog-backed) with an engine and a dedicated admin connection. Admin work never rides the app's default connection. |
| **Engine** | The only layer that builds SQL. Per-dialect (MySQL/MariaDB/Postgres/SQL Server/SQLite), it turns validated value objects into exact statements and declares its honest capabilities. |
| **Catalog** | A dedicated, optionally-encrypted store of what DBConsole manages. Reads are live from the server; the catalog is history, never the source of truth. |
| **Service** | `DatabaseManager` / `AccountManager` / `PrivilegeManager` / wizard — authorize → resolve → check capability → ask the engine → run → audit. Every consumer calls these. |
| **Secret vault** | Four drivers (app-key, KMS, HashiCorp Vault, reference) behind one seam. Secrets redact themselves everywhere. |
| **RBAC** | Deny-by-default, scope-aware (global ⊇ server ⊇ database), builtin or Spatie-backed. |

## <a name="documentation"></a>Documentation

Full documentation is hosted at **<https://opensource.simtabi.com/documentation/laranail/db-console/>**.

### Guides

- [Installation](docs/installation.md) — requirements, install, the minimal admin account, catalog setup.
- [Getting started](docs/getting-started.md) — register a server, run the wizard, read the audit trail.
- [Configuration](docs/configuration.md) — every `laranail.db-console.*` key.
- [Architecture](docs/architecture.md) — the layers, the invariants, and why they are shaped this way.
- [Release](docs/release.md) — versioning and the release process.
- [Comparison](docs/comparison.md) — why this exists and how it differs from a raw admin GUI.

### Reference

- [Engines](docs/tools/engines.md) · [Servers & connections](docs/tools/servers.md) · [Secret vault](docs/tools/secret-vault.md) · [Catalog encryption](docs/tools/catalog-encryption.md)
- [RBAC](docs/tools/rbac.md) · [Audit trail](docs/tools/audit.md) · [Wizard & rollback](docs/tools/wizard-executor.md) · [Attach / detach](docs/tools/attach-detach.md) · [Reconcile](docs/tools/reconcile.md)
- [doctor](docs/tools/doctor.md) · [Commands](docs/tools/commands.md) · [REST API](docs/tools/api.md) · [Webhooks](docs/tools/webhooks.md) · [Events & notifications](docs/tools/events-notifications.md)

### Recipes

- [Provision a minimal admin per engine](docs/recipes/provision-minimal-admin.md)
- [Configure a KMS / Vault / reference secret driver](docs/recipes/configure-secret-driver.md)
- [Issue an API token](docs/recipes/issue-api-token.md)
- [Verify a webhook signature](docs/recipes/verify-webhook-signature.md)
- [Use the Spatie RBAC driver](docs/recipes/spatie-rbac.md)

## Stability

Pre-1.0. The public surface — services, value objects, the shared validation layer, events, and CLI names — is settling toward a stable 1.0. Breaking changes are called out in [UPGRADING.md](UPGRADING.md) and the [CHANGELOG](CHANGELOG.md).

## Local development

```bash
composer install
composer test                                 # Pest — runs entirely on SQLite, no external servers
composer lint                                 # Pint, PHPStan (level 8), Rector
```

The suite is self-contained (in-memory SQLite). Live multi-engine integration against MySQL/MariaDB/PostgreSQL — and a full demo UI — live in [`laranail/db-console-boilerplate`](https://github.com/laranail/db-console-boilerplate). See [CONTRIBUTING.md](CONTRIBUTING.md) for conventions.

## Sister packages

- [`laranail/console`](https://github.com/laranail/console) — the command base, formatter, and prompter.
- [`laranail/package-tools`](https://github.com/laranail/package-tools) — the service-provider builder and install flow.
- [`laranail/enumerator`](https://github.com/laranail/enumerator) — the translatable enum toolkit.
- [`laranail/db-tools`](https://github.com/laranail/db-tools) — optional backup, inspection, and audit helpers.
- [`laranail/db-console-webui`](https://github.com/laranail/db-console-webui) — the thin Livewire + Flux front end for this package.

## Community

Questions and ideas are welcome in [GitHub Discussions](https://github.com/laranail/db-console/discussions); bugs in [Issues](https://github.com/laranail/db-console/issues).

## Contributing & security

See [CONTRIBUTING.md](CONTRIBUTING.md). Report vulnerabilities per [SECURITY.md](SECURITY.md) (disclosure to `opensource@simtabi.com`), never in a public issue.

## License

MIT — see [LICENSE](LICENSE). Copyright (c) 2026 Simtabi LLC.

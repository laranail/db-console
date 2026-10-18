# Servers & connections

`ServerRegistry` and `AdminConnection` are the only components that touch a managed server.

## Overview

A **server** is a named target with an engine and a dedicated admin connection. `ServerRegistry` resolves servers from config (`servers.{name}`) and the catalog, caches them, and checks reachability. `AdminConnection` is the sole thing that runs a statement against a server; it translates every driver error into a DBConsole exception (never leaking SQL or credentials) and always runs over the dedicated admin connection — never your app's default.

## TLS

Each server carries a `tls` block (`enabled`, `verify`, `ca`, `cert`, `key`). When `enabled` is true, `ServerRegistry` writes it into the admin connection before connecting, overriding the same keys in `config/database.php`:

| Driver | What the block sets |
|---|---|
| `mysql`, `mariadb` | `options`: `Pdo\Mysql::ATTR_SSL_CA`, `ATTR_SSL_CERT`, `ATTR_SSL_KEY`, `ATTR_SSL_VERIFY_SERVER_CERT` |
| `pgsql` | `sslmode` (`verify-full` when `verify`, else `require`), `sslrootcert`, `sslcert`, `sslkey` |
| `sqlsrv` | `encrypt=yes`, `trust_server_certificate` (`no` when `verify`) |

A key the block leaves null is not removed, so a CA set on the connection itself still applies. With `enabled` false the connection is left exactly as configured.

> pdo_mysql starts TLS only when a CA, certificate, key, CA path or cipher is set. `tls.enabled=true` with no `tls.ca` connects in plaintext, and `doctor` reports it. For a managed provider, download its CA certificate and point `tls.ca` at it.

### Measured, not assumed

`TlsChecker` asks the live session whether it is encrypted (`Ssl_cipher` on MySQL, `pg_stat_ssl` on PostgreSQL, `CONNECTIONPROPERTY('encrypt_option')` on SQL Server). None of these needs a privileged account, so the check works under a managed provider's admin user.

| Status | Meaning | `doctor`, non-local server |
|---|---|---|
| `required_ok` | encrypted, `enabled` and `verify` on | ok |
| `unverified` | encrypted, but `verify` off or `enabled` off | warning |
| `off` | the session is plaintext, whatever the config says | error |
| `unmeasured` | `enabled` on, but the session could not be asked | warning |
| `n/a` | SQLite | — |

A server is local when every host it can reach is `localhost`, `127.0.0.1` or `::1`, including a host given only in `url` (`DATABASE_URL`) and the `read`/`write` hosts, or when it connects over a Unix socket (MySQL `unix_socket`, a PostgreSQL socket directory as `host`). A tunnelled server is local only when its target is a socket or the loopback on the SSH host: the connection's own `127.0.0.1` says nothing. Local servers are exempt from these findings.

## SSH tunnels

A server's `ssh` block routes its admin connection through an SSH tunnel, with the `openssh` or `phpseclib` driver, a pinned host key, and a TCP or Unix socket at either end. See [SSH tunnels and sockets](ssh-tunnels.md).

## Hosting

`servers.{name}.hosting` says where the server runs: `self_hosted` (the default), `digitalocean`, `rds`, `cloud_sql`, or `generic` for another managed provider. An absent key is `self_hosted`, so an existing config behaves as before; an unknown value fails with the list of valid ones rather than falling back.

`Hosting::profile(EngineType)` turns it into a `HostingProfile`:

| | Self-hosted | Managed |
|---|---|---|
| Accounts listed | all | without the provider's (`doadmin`, `_dodb`; `rdsadmin`, `rds_*`; `cloudsql*`) and the engine's (`mysql.*`, `mariadb.sys`, `pg_*`) |
| Host of a new MySQL account | `accounts.default_host` | `%` |
| Backup before drop | a dump | none; the provider's point-in-time recovery is the restore path, unless `backup.dump_on_managed` is on |

`ServerRegistry::capabilities($server)` returns the engine's capabilities with this profile merged in (`hosting`, `default_account_host`, `provider_backups` in `toArray()`); prefer it to `engine()->capabilities()`, which knows nothing of where the server runs. `ServerRegistry::defaultAccountHost($server)` is what `user:create` uses when no `--host` is given.

[Managed databases](../managed-databases.md) puts these together, and [Connect to DigitalOcean Managed Databases](../recipes/digitalocean.md) walks through one provider.

`doctor` warns when a self-hosted server's host belongs to a provider it recognises (`*.ondigitalocean.com`, `*.rds.amazonaws.com`). The provider account lists come from each provider's documentation; only the engine's own accounts are checked against live servers in CI.

## Multi-server isolation

An operation names its server; the registry resolves that server's engine and connection. An operation on one server can never touch another.

---

[← Docs index](../../README.md#documentation)

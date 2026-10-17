# Managed databases

How db-console runs against a provider-managed MySQL, MariaDB or PostgreSQL server (DigitalOcean, Amazon RDS, Google Cloud SQL or another provider), and what changes once a server declares one.

## What a managed server is like

A managed server differs from one you run yourself in ways db-console has to respect:

- **It is reached over the network, and TLS is required.** The provider gives you a connection string and a CA certificate, never a socket.
- **The admin is not a superuser.** On MySQL there is no `SUPER`; on PostgreSQL it is `CREATEDB CREATEROLE` without `rolsuper` (DigitalOcean's `doadmin` has that shape).
- **The provider owns some accounts.** They exist on the server, and nothing you run should alter or drop them.
- **The provider keeps backups.** Daily snapshots plus point-in-time recovery cover more than a dump taken at drop time.
- **MySQL may enforce extra settings.** For example `sql_require_primary_key=ON` and GTID replication.

## Declare the hosting

Set the server's `hosting` (env `DB_CONSOLE_PRIMARY_HOSTING` for the `primary` server):

```php
// config/db-console.php
'servers' => [
    'primary' => [
        'engine'     => env('DB_CONSOLE_ENGINE', 'pgsql'),
        'connection' => env('DB_CONSOLE_CONNECTION', 'db_console_admin'),
        'tls'        => [
            'enabled' => true,
            'verify'  => true,
            'ca'      => env('DB_CONSOLE_PRIMARY_TLS_CA'),
        ],
        'hosting' => env('DB_CONSOLE_PRIMARY_HOSTING', 'digitalocean'),
    ],
],
```

The values are `self_hosted` (the default), `digitalocean`, `rds`, `cloud_sql` and `generic`. Use `generic` for any other provider. An absent key means `self_hosted`, so nothing changes until you set it; an unknown value fails with the list of valid ones. The full behaviour is in [Servers & connections](tools/servers.md#hosting).

Once a server declares a managed hosting:

| | What changes |
|---|---|
| Accounts | `AccountManager::list()` leaves out the provider's accounts (`doadmin`, `_dodb`; `rdsadmin`, `rds_*`; `cloudsql*`) and the engine's (`mysql.*`, `mariadb.sys`, `pg_*`). `exists()` still sees them. |
| New MySQL accounts | Without `--host`, `user:create` uses `%` instead of `accounts.default_host`. A managed server is never reached from `localhost`. |
| Drop | No dump is taken first. The drop logs `database.backup.provider` with a `restore_point` timestamp, the moment to restore to. `backup.dump_on_managed=true` keeps dumping as well. See [Backup before drop](tools/backups.md). |
| doctor | The hosting checks below. |

## Connect over TLS

Download the provider's CA certificate and point `tls.ca` at it (`DB_CONSOLE_PRIMARY_TLS_CA`). With `tls.enabled` and `tls.verify` on, the block is written into the admin connection before it opens:

- PostgreSQL: `sslmode=verify-full` and `sslrootcert`.
- MySQL: `Pdo\Mysql::ATTR_SSL_CA` with server-certificate verification.

pdo_mysql starts TLS only when a CA (or certificate, key, CA path or cipher) is set, so `tls.enabled=true` without `tls.ca` connects in plaintext. `doctor` reports that as an error, because it measures the live session rather than reading config. See [Servers & connections](tools/servers.md#tls).

The connection's `url` (`DB_URL`) works as it does anywhere in Laravel. db-console expands it before it applies TLS, and backups dump the server the `url` names.

## Use a least-privilege admin

Point the admin connection at an account that can create databases and accounts and nothing more:

- **PostgreSQL:** `CREATE ROLE db_console_admin WITH LOGIN CREATEDB CREATEROLE PASSWORD '…';`. A provider's own admin of the same shape (such as `doadmin`) also passes `doctor`.
- **MySQL / MariaDB:** the `CREATE USER` grant plus the grantable privileges `doctor` prints. Never `ALL PRIVILEGES` or `SUPER`.

[Provision a minimal admin per engine](recipes/provision-minimal-admin.md) has the exact statements.

## What doctor checks

`php artisan laranail::db-console.doctor` checks every server for reachability, TLS and the admin's privileges. It adds these checks from the hosting:

| Check | Severity | When |
|---|---|---|
| `server:<name>:hosting` | warning | a `self_hosted` server's host belongs to DigitalOcean (`*.ondigitalocean.com`) or RDS (`*.rds.amazonaws.com`) |
| `server:<name>:accounts` | warning | MySQL family: the admin cannot read `mysql.user` |
| `server:<name>:server-settings` | info | MySQL family: `sql_require_primary_key` and `gtid_mode` |
| `server:<name>:forced-drop` | warning | PostgreSQL: the admin cannot end other roles' sessions (`pg_signal_backend`) |
| `server:<name>:pooler` | warning | PostgreSQL: the connection uses a known pooler port such as DigitalOcean's 25061 |

The full list is in [doctor](tools/doctor.md).

## Backups

A dump made at drop time is one copy from one moment. The provider's point-in-time recovery restores to any second of its retention window, and it does not depend on the client tools on the application server. On a managed hosting db-console therefore takes no dump before a drop by default, and logs the restore point instead.

When `backup.dump_on_managed` is on, the dump goes through laranail/db-tools. That path:

- passes the TLS settings and the connection's `url` to `mysqldump`, `pg_dump` and `pg_restore`;
- runs `--single-transaction`, `--no-tablespaces` and `--set-gtid-purged=OFF` (when the client supports it) on MySQL;
- writes archives with `--no-owner --no-privileges` on PostgreSQL;
- refuses a PostgreSQL client older than the server.

See db-tools' [backup & restore](https://opensource.simtabi.com/documentation/laranail/db-tools/tools/backup-restore) page.

## Providers

- **DigitalOcean:** step by step in [Connect to DigitalOcean Managed Databases](recipes/digitalocean.md).
- **Amazon RDS** (`rds`) **and Google Cloud SQL** (`cloud_sql`): the reserved account names come from each provider's documentation. CI checks only the engine's own accounts against live servers.

---

[← Docs index](../README.md#documentation)

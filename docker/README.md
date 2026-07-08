# db-console docker layer

A reproducible multi-engine stack so `doctor`, the multi-server switcher, and
the feature suite have several real servers to exercise — without installing
five databases by hand.

## Bring it up

```bash
docker compose -f docker/compose.yaml up -d          # mysql, mariadb, postgres, mailpit
docker compose -f docker/compose.yaml --profile sqlsrv up -d   # + SQL Server 2022 (x86-only, heavy)
```

Each database service runs an init script provisioning a minimal-privilege
`db_console_admin` account (the same statements the docs recommend for
production) — so `doctor` passes out of the box and the account is never root.

## Host-mapped ports

| Service   | Host port | Admin user         | Admin password             |
|-----------|-----------|--------------------|----------------------------|
| MySQL 8.4 | `33061`   | `db_console_admin` | `admin-secret-change-me`   |
| MariaDB 11| `33062`   | `db_console_admin` | `admin-secret-change-me`   |
| Postgres 16| `54329`  | `db_console_admin` | `admin-secret-change-me`   |
| SQL Server 2022 | `14330` | `sa` (bootstrap) | `Root-not-for-app-use1` |
| Mailpit   | `10250` (web) / `10251` (SMTP) | — | — |

The feature suite reads `DB_CONSOLE_TEST_{MYSQL,MARIADB,PGSQL,SQLSRV}_*`
environment variables (defaulting to these ports) and skips cleanly when a
server is unreachable, so CI stays green without Docker and the full matrix
runs locally.

## Images

- `app/Dockerfile` — PHP 8.4 with `pdo_mysql`, `pdo_pgsql`, `pdo_sqlite`, and
  `pdo_sqlsrv`, pinned so local and CI are the same machine.
- `app/Dockerfile.sqlcipher` — a variant with `pdo_sqlite` built against
  SQLCipher (`pdo_sqlcipher`), the reference environment for whole-file
  catalog encryption (`doctor` then reports `whole_file` mode).

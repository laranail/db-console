# Run the Docker multi-engine stack

Bring up MySQL, MariaDB, Postgres (and optionally SQL Server) for local testing.

## Steps

```bash
docker compose -f docker/compose.yaml up -d
docker compose -f docker/compose.yaml --profile sqlsrv up -d   # optional, heavy
```

Each service provisions a minimal `db_console_admin` on startup, so `doctor` passes out of the box. The test suite reads `DB_CONSOLE_TEST_{MYSQL,MARIADB,PGSQL,SQLSRV}_*` env vars and skips cleanly when a server is down. See [docker/README.md](../../docker/README.md).

---

[← Docs index](../../README.md#documentation)

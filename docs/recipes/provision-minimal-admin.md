# Provision a minimal admin per engine

Create the least-privilege admin account DBConsole should use — never root.

## Steps

**MySQL / MariaDB**

```sql
CREATE USER 'db_console_admin'@'%' IDENTIFIED BY '…';
GRANT CREATE USER ON *.* TO 'db_console_admin'@'%';
GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, ALTER, DROP, INDEX, REFERENCES, CREATE TEMPORARY TABLES, LOCK TABLES, EXECUTE, CREATE VIEW, SHOW VIEW, CREATE ROUTINE, ALTER ROUTINE, EVENT, TRIGGER ON *.* TO 'db_console_admin'@'%' WITH GRANT OPTION;
```

`CREATE USER` covers creating, altering and dropping accounts. A grant needs both the privilege and `GRANT OPTION` on it, so the second statement lists every privilege a preset can hand out; on `*.*` its `CREATE` and `DROP` also cover databases. `doctor` prints the same statements, and the `live-mysql` CI job provisions against MySQL and MariaDB as exactly this account.

**PostgreSQL** (not a superuser)

```sql
CREATE ROLE db_console_admin WITH LOGIN CREATEDB CREATEROLE PASSWORD '…';
```

On PostgreSQL 16 and later this admin can drop only roles it created, since dropping a role needs ADMIN OPTION on it. A managed service's admin user, such as DigitalOcean's `doadmin`, has the same shape. Connect it to the server directly, not through a connection pooler, and use PostgreSQL 13 or later; `doctor` fails a superuser, a role missing either attribute, and an older server, and warns on a known pooler port.

Then point the server's admin connection at this account and run `php artisan laranail::db-console.doctor` — it confirms the account is appropriately scoped and not root-like. See [doctor](../tools/doctor.md).

---

[← Docs index](../../README.md#documentation)

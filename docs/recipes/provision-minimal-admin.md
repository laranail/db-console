# Provision a minimal admin per engine

Create the least-privilege admin account DBConsole should use — never root.

## Steps

**MySQL / MariaDB**

```sql
CREATE USER 'db_console_admin'@'%' IDENTIFIED BY '…';
GRANT CREATE, DROP, ALTER, INDEX, REFERENCES, CREATE USER, RELOAD ON *.* TO 'db_console_admin'@'%';
GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, DROP, ALTER, INDEX, EXECUTE, CREATE VIEW, SHOW VIEW
  ON *.* TO 'db_console_admin'@'%' WITH GRANT OPTION;
```

**PostgreSQL** (not a superuser)

```sql
CREATE ROLE db_console_admin WITH LOGIN CREATEDB CREATEROLE PASSWORD '…';
```

On PostgreSQL 16 and later this admin can drop only roles it created, since dropping a role needs ADMIN OPTION on it. A managed service's admin user, such as DigitalOcean's `doadmin`, has the same shape.

Then point the server's admin connection at this account and run `php artisan laranail::db-console.doctor` — it confirms the account is appropriately scoped and not root-like. See [doctor](../tools/doctor.md).

---

[← Docs index](../../README.md#documentation)

# Upgrading

## Unreleased

### PostgreSQL provisioning

Postgres provisioning works on any server now, which changes what some calls do:

- **Dropping a role reassigns what it owns to the admin.** Before, `DROP ROLE` failed while the role
  owned anything. Now its objects in every database become the admin's (`REASSIGN OWNED BY ... TO
  CURRENT_USER`). Nothing is deleted, and its privileges are revoked. The admin must hold ADMIN
  OPTION on the role, which it does for roles it created.
- **Dropping a database terminates its sessions** (`DROP DATABASE ... WITH (FORCE)`, PostgreSQL 13
  or later): those the admin may signal. A session of a role it cannot signal still blocks it.
- **Revoking a privilege revokes the Postgres privilege it maps to**, even when another privilege
  the role holds maps to the same one: `show_view` and `select` are both `SELECT`; `create`,
  `create_view` and `create_routine` are all schema `CREATE`. Revoke fails closed.
- **Grants go to the target database.** If you worked around the old behaviour by granting inside
  the target yourself, those grants still stand. `ALTER`, `DROP`, `INDEX`, `ALTER ROUTINE`, `LOCK
  TABLES` and `EVENT` now grant nothing on Postgres, rather than `SELECT`, `CREATE` or `EXECUTE` on
  tables. The role holds them on the tables it creates itself.
- **An empty Postgres database is no longer backed up before a drop**, the same as on MySQL.
- **`DatabaseManager`, `AccountManager` and `PrivilegeManager` take a `ScopedConnections`** as a new
  last constructor argument. Resolved from the container, nothing changes. Code that calls `new` on
  one of them must pass it.
- **`AdminConnection::run()` throws `LogicException` for a statement scoped to another database.**
  Engines in this package only scope Postgres statements. An engine of your own is affected only if
  it calls `Statement::inDatabase()`.

## From pre-release to 0.x

No published releases yet. This file will document breaking changes and
migration steps between released versions.

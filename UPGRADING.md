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
- **A Postgres grant is all or nothing, and can throw `GrantNotApplied`.** Where the admin lacks the
  grant option on something the set covers (a database or schema another role owns), nothing is
  granted and the exception names what was missing. Before, part of the grant went through and the
  call reported success. On PostgreSQL 14 and earlier, PUBLIC holds `CREATE` on schema `public`, so
  that case cannot arise there; it also means any role can create tables in `public` on those
  versions, which a non-superuser admin cannot change.
- **A Postgres role drop refuses up front** with `InsufficientPrivilege` when the role has objects
  or privileges in a database the admin cannot connect to. Before, it failed part way.
- **New last constructor arguments**, resolved from the container as before: `DatabaseManager` and
  `PrivilegeManager` take a `ScopedConnections`, and `AccountManager` takes an
  `OwnershipReleasingDrop`. Code that calls `new` on one of them must pass it.
- **`OwnershipReleasingEngine` gained five methods** (`holdsPrivilegesOf`, `takeMembership`,
  `releaseMembership`, `releaseOwnershipIn`, `dropReleasedAccount`), and `dependentDatabases()`
  returns a second column, `can_connect`. An engine of your own implementing it must add them.
- **`AdminConnection::run()` throws `LogicException` for a statement scoped to another database.**
  Engines in this package only scope Postgres statements. An engine of your own is affected only if
  it calls `Statement::inDatabase()`.

## From pre-release to 0.x

No published releases yet. This file will document breaking changes and
migration steps between released versions.

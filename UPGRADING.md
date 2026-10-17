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
- **`showGrants()` returns different lines on Postgres.** Each is `<kind> <database> <object>
  <privilege>` (`table shop public.orders SELECT`), where it was `<schema> <table> <privilege>` for
  tables in the admin's own database only. Code parsing the old lines must change.
- **A non-UTF8 Postgres database uses the `C` locale**, created from `template0`. Text sorts by byte
  value. `UTF8` databases are created exactly as before.
- **Postgres role passwords are sent as a SCRAM-SHA-256 verifier.** The server stores it whatever its
  `password_encryption` setting, so a server set to `md5` gets a SCRAM verifier for these roles; any
  client from PostgreSQL 10 on can log in with it. A password that SASLprep would rewrite (non-ASCII
  spaces, soft hyphens, or characters NFKC changes, such as fullwidth letters) is refused with
  `WeakPassword`, because the verifier would not match it.
- **`doctor` can now fail on a Postgres server**: for a superuser admin, an admin without `CREATEDB`
  or `CREATEROLE`, or a server older than 13. A superuser admin also raises `SuspiciousActivity`.
  `DoctorService` takes a `PostgresAdminCheck` as a new last constructor argument, resolved from the
  container.
- **`AdminConnection::run()` throws `LogicException` for a statement scoped to another database.**
  Engines in this package only scope Postgres statements. An engine of your own is affected only if
  it calls `Statement::inDatabase()`.

## From pre-release to 0.x

No published releases yet. This file will document breaking changes and
migration steps between released versions.

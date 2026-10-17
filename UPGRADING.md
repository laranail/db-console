# Upgrading

## Unreleased

### TLS is applied and measured

The `tls` block on a server is now written into its admin connection, and
`TlsChecker` reports what the live session negotiated instead of what config
says. Three things can change on upgrade:

- **MySQL/MariaDB with `tls.enabled=true` and no `tls.ca` now fails `doctor`
  on a remote server.** pdo_mysql never started TLS without a CA, certificate
  or key, so these sessions were always plaintext; they are now reported as
  such. Set `DB_CONSOLE_PRIMARY_TLS_CA` (or `tls.ca`) to the server's CA.
- **PostgreSQL with `tls.enabled=true` now connects with `sslmode=require`**
  (`verify-full` when `verify` is on). A server without SSL that used to
  accept a plaintext connection now refuses it.
- **`encryption:status` and `TlsChecker::status()` open the admin
  connection.** An unreachable server reports `unmeasured`.

A connection configured only by `url` (`DATABASE_URL`) is no longer treated
as local, so it is no longer exempt from the TLS findings.

## From pre-release to 0.x

No published releases yet. This file will document breaking changes and
migration steps between released versions.

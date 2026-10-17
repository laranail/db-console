# SSH tunnels and sockets

A server's admin connection can reach the database through an SSH tunnel, or over a local Unix socket, instead of a direct TCP connection.

## Overview

Set the server's `ssh` block and `ServerRegistry` opens the tunnel the first time the server is used, points the admin connection at the tunnel's local end, and closes the tunnel when the application terminates. Everything that reads the admin connection's config goes through it: scoped per-database connections and backups included.

```php
'servers' => [
    'primary' => [
        'engine'     => 'pgsql',
        'connection' => 'db_console_admin',
        'tls'        => ['enabled' => true, 'verify' => true, 'ca' => '/etc/ssl/do-ca.crt'],
        'ssh'        => [
            'enabled'  => true,
            'driver'   => 'openssh',                 // or phpseclib
            'host'     => 'bastion.example.com',
            'port'     => 22,
            'user'     => 'tunnel',
            'host_key' => 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAA...',
            'key'      => storage_path('app/ssh/db-console'),
        ],
    ],
],
```

| Key | Default | Meaning |
|---|---|---|
| `driver` | `openssh` | `openssh` runs the `ssh` binary; `phpseclib` is pure PHP, for hosts without one |
| `host`, `port`, `user` | port 22 | the SSH server |
| `host_key` | required | the server's public host key line; there is no trust on first use |
| `key` / `key_secret` | one required | a private key file, or a secrets-driver reference holding the key |
| `remote_host`, `remote_port` | the admin connection's host and port | where the SSH server connects onward to |
| `remote_socket` | none | forward to a Unix socket on the SSH host instead |
| `local` | `port` | `port` (127.0.0.1) or `socket` (a Unix socket in a 0700 directory) |
| `socket_directory` | the system temp directory | where the tunnel's private directory is created |
| `connect_timeout`, `keepalive` | 10, 15 | seconds |
| `binary` | `ssh` | the OpenSSH client to run |

## Pinning the host key

`host_key` is required, and both drivers refuse a server that presents any other key before they offer a credential. Take the key from the server itself, or scan it over a network you trust:

```bash
ssh-keyscan -t ed25519 bastion.example.com
```

A `known_hosts` line (`host ssh-ed25519 AAAA...`) is accepted too. `doctor` prints the pinned key's SHA256 fingerprint so it can be compared with `ssh-keygen -lf /etc/ssh/ssh_host_ed25519_key.pub` on the server.

## Private keys

`key` is a path the PHP process can read. `key_secret` is a reference resolved through the configured [secret vault](secret-vault.md); the key is written to a `0600` file inside the tunnel's `0700` directory and deleted with it when the tunnel closes. Key-based authentication only: passwords and agents are never used.

## Drivers

**openssh** runs `ssh -N -L <local>:<remote>` with `BatchMode=yes`, `StrictHostKeyChecking=yes` against a one-line `known_hosts` it writes, `ExitOnForwardFailure=yes`, `ServerAliveInterval`, `IdentitiesOnly=yes` and `IdentityAgent=none`, and `-F /dev/null` so nothing in the host's `~/.ssh/config` can change what runs.

**phpseclib** needs `phpseclib/phpseclib` `^4.0.1`. It forwards from a child PHP process, because the request that uses the tunnel is blocked inside PDO and cannot move bytes itself. The child receives its settings, key included, on stdin rather than its command line, checks the host key after key exchange and before authenticating, and exits when its parent closes its stdin or dies. It opens `direct-tcpip` channels for a TCP target and `direct-streamlocal@openssh.com` channels for a socket target.

## Sockets

### Local sockets, no tunnel

A server on the same host can skip TCP entirely through `config/database.php`: `unix_socket` for MySQL and MariaDB, or a socket directory as `host` for PostgreSQL (`'host' => '/var/run/postgresql'`). `doctor` treats both as local, so they are exempt from the TLS findings.

### Remote sockets through the tunnel

`remote_socket` forwards to a Unix socket on the SSH host, so a database that listens only on its socket can be managed remotely:

```php
'remote_socket' => '/var/run/postgresql/.s.PGSQL.5432',   // PostgreSQL
'remote_socket' => '/var/run/mysqld/mysqld.sock',         // MySQL / MariaDB
```

### A socket as the tunnel's local end

With `local => 'socket'` the tunnel listens on a `0600` socket inside a `0700` directory instead of a 127.0.0.1 port, which any local user could connect to. For PostgreSQL the socket is named `.s.PGSQL.5432`, the name libpq looks for in a socket directory. SQL Server cannot connect over a socket.

## TLS through a tunnel

The `tls` block still applies, and through a TCP forward the session is TLS from PHP to the database. Two limits come from the clients, not from DBConsole:

- **Hostname verification.** The client dials 127.0.0.1. PostgreSQL keeps verifying the real name: with `sslmode=verify-full` the connection's host becomes `db.example.com;hostaddr=127.0.0.1`, which tells libpq to dial the forward and check the certificate against the name. pdo_mysql and SQL Server have no equivalent, so with `verify` on they check the certificate against 127.0.0.1 and fail unless it names that address. Through a tunnel to MySQL, either set `tls.verify=false` (the session is still encrypted, and the SSH host key is pinned) or forward to `remote_socket`.
- **A socket carries no TLS.** Neither libpq nor mysqlnd negotiates TLS over a Unix socket. `local => 'socket'` with `tls.enabled` and a target across the network is refused, because the hop from the SSH host to the database would be plaintext while config says TLS. A target that is a socket, or the loopback, on the SSH host never leaves that host, and `doctor` counts it as local.

## Doctor

| Check | Severity | When |
|---|---|---|
| `server:<name>:ssh-driver` | error | the `ssh` binary or phpseclib is not available |
| `server:<name>:ssh-host-key` | ok | always; shows the pinned key's fingerprint |
| `server:<name>:ssh-tunnel` | error | the tunnel did not open: the host key did not match, authentication was refused, or the forward failed |

The reachability check that follows runs its round trip through the tunnel. A tunnel failure stops `doctor` for that server, so the report names the tunnel rather than a vaguer "unreachable".

## Lifetime

One tunnel per server per process. It opens on first use, is reused while it lives, is reopened if it dies, and is closed (with its directory, key and socket removed) when the application terminates, or at shutdown for anything that never reaches terminate. A queue worker keeps its tunnel for as long as it runs.

---

[← Docs index](../../README.md#documentation)

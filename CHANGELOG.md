# Changelog

All notable changes to `laranail/db-console` are documented in this file.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/)
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Changed

- **Test credentials are generated at run time.** Passwords, secrets and connection-URL
  passwords in the tests come from `TestCredentials` instead of literals, which secret scanning
  reported as leaks. A guard test fails on any literal left behind; deliberate fixed values carry
  a `@credential-literal-exempt` marker and a reason. The `laranail/package-tools` floor is now
  `^0.1.9`, the release that ships these helpers.
- **No credential-looking literal is left in the tests secret scanning flagged.** The vault tests'
  admin secret, rotated secret and KMS data keys, the existing-account password in the policy tests
  and `FakeProvisioningEngine`, and the SSH driver test's host key are generated at run time. The
  RFC 7677 SCRAM vectors and the `TunnelKeys` constants stay fixed, since their tests prove exact
  bytes. `NoLiteralTestCredentialsTest` now also refuses a literal of any strength in a constant,
  `define()`, named argument or Livewire property named for a password, secret or token.

## [0.1.5] - 2026-10-18

### Security

- **Three API reads checked no permission.** `GET audit` returned the audit trail, and
  `GET webhooks` every subscription with its target URL, to any authenticated caller; `GET servers`
  listed every registered server. They now need `audit.view` and `webhook.manage`, and the server
  list holds only the servers the caller has `server.view` on.

### Added

- **API endpoints for the request classes that had no route:** `PATCH servers/{server}/accounts/{username}`
  (host move, grants kept), `POST …/accounts/{username}/password`, `DELETE servers/{server}/grants`
  (revoke), `POST` and `DELETE servers/{server}/attachments` (bulk attach and detach, 207 on a
  partial run), `POST tokens` and `POST role-assignments`. Issuing a token needs `token.manage` and
  caps its abilities by both the caller's and the holder's (`TokenManager::issueFor`), so an Admin
  cannot mint a token with an Owner's abilities; assigning a role needs `settings.manage` and refuses
  a role that grants nothing. `TokenManager::issue()` takes an optional expiry.

- **MySQL and MariaDB verify the server's real name through an SSH tunnel.** pdo_mysql checks a
  certificate against the host it dials, which through a tunnel is 127.0.0.1, so `tls.verify=true`
  failed for every real certificate. A TLS relay (`Tunnels\Relay\MysqlTlsRelay`, a PHP process
  started with the tunnel) now takes the client's place in MySQL's in-protocol TLS upgrade,
  verifies the certificate against the database's name and `tls.ca`, and passes everything else
  through; PDO talks to it in plaintext over a `0600` socket in the tunnel's `0700` directory. A
  certificate that does not match fails the login with a MySQL error naming why. Proven live in the
  `live-ssh` job against a MySQL that refuses plaintext, through both drivers and both tunnel ends.
  PHP starts TLS only on a tcp:// stream, so for a tunnel ending in a Unix socket (`ssh.local=socket`)
  the relay runs TLS over a loopback TCP pair of its own, spliced to the socket: its listener lives
  until the relay's own connection is accepted, that one peer is checked, and what crosses it is
  already TLS.
- `Tunnels\Contracts\TlsRelayLauncher` (bound to `Tunnels\Relay\VerifyingRelay`) and
  `Tunnels\Contracts\TerminatesTls`, and `Tunnels\Support\WorkerProcess`, the child-process launch
  the phpseclib driver and the relay share.
- **`doctor` reports whether each server's backup-before-drop can run: `server:<name>:backup-client`.**
  A missing `mysqldump`, or a `pg_dump` older than the server, used to surface only when a drop of a
  non-empty database needed the backup, and with `backup.abort_drop_on_failure` on the drop was then
  refused. Doctor now asks laranail/db-tools without dumping, through the same admin connection and
  tunnel a snapshot uses. Error when a drop would be refused, warning when it would go ahead without a
  backup, nothing when backups are off or the provider keeps point-in-time recovery.
- **`Backup\PreflightsBackups`** (`preflight(string $server): ?BackupClientStatus`), implemented by
  `DbToolsBackupService`. Separate from `BackupService`, so a host's own implementation of that is
  unaffected; doctor asks for it with `instanceof`. The db-tools preflight is read by method, so a
  db-tools without it (0.1.7 and earlier) simply yields no finding.
- **Four config keys that were read by nothing now work.** Each one's shipped value keeps the
  behaviour it had before:
  - `rbac.seed_default_roles` gates the install step that seeds the shipped roles
    (`Access\DefaultRoleSeeder`).
  - `webhooks.queue` names the queue webhook deliveries and their retries go to; `true` keeps the
    default queue.
  - `presets` is validated at boot against the privilege allow-list, and a bad preset fails the
    boot with `Exceptions\PresetMisconfigured` (`preset.misconfigured`), naming it
    (`Domain\Privileges\CustomPresets`).
  - `catalog_encryption.encrypt_topology_columns` set to `false` writes a server's `host` and an
    account's `username` and `host` in plaintext (`Models\Casts\TopologyColumn`). Either form
    reads back, so no migration is needed.

### Changed

- **The drop endpoints are validated by `DropDatabaseRequest` and `DropAccountRequest`.** The name
  in the route is validated, a wrong confirmation is a 422 with an error on `confirm`, and dropping
  an account requires `host`. It used to default to `%`, dropping `user@%` when the caller meant
  another host. With `api.confirm_destructive` off the confirmation is still not required.

- **Tests generate their passwords at run time.** A fixed strong sample, repeated across the test
  suite since the first release, was reported by secret scanning as a leaked credential. Tests now
  call `testPassword()`, which uses `Password::generate()`, and
  `NoLiteralTestCredentialsTest` fails on a strong-looking literal in a password position. The live
  provisioning jobs' account passwords are generated per run too.

- **laranail/db-tools `^0.1.8`** (was `^0.1.7`) in `require-dev` and `suggest`. doctor's
  `server:<name>:backup-client` check calls db-tools' `preflight()`, which first ships in 0.1.8; with
  0.1.7 the check yields no finding.
- **The Tests workflow defaults every job to `contents: read`.** The `pest` job, which calls the
  shared reusable workflow, set no permissions and so ran with the repository's default token
  scope; the live jobs already set `contents: read` and keep it.

### Fixed

- **`secrets.reference.token` is in the shipped config** (env `DB_CONSOLE_DOPPLER_TOKEN`). The
  Doppler reference resolver read it, but the config never defined it, so a published config gave
  the resolver no token and every Doppler lookup failed as misconfigured. Documented in the
  configuration and secret vault pages.

## [0.1.4] - 2026-10-18

### Added

- **A failed wizard run takes back the adoptions it made.** When the provisioning wizard adopts
  an account or database and a later step fails, the rollback now stops recording that resource as
  managed (`AdoptionUndone`, a Security-category notification and webhook event). It still never
  touches the server, where the resource is as it was before the run. An adoption an earlier run
  made is kept.
- **`Services\Contracts\TracksManagedResources`** (`managesAccount()`, `managesDatabase()`), an
  optional interface beside `Catalog`, implemented by `DbConsoleCatalog` and `NullCatalog`. It is
  how an adoption knows it is the first. A host catalog that does not implement it keeps the old
  behaviour: adoptions report `newly_managed: false` and a rollback leaves their records alone.
- Adoption results carry **`newly_managed`**: true when that call put the resource under
  management for the first time. Results from `adopt_and_apply` also carry it.
- **The live SSH job proves verified TLS inside the tunnel.** A PostgreSQL with TLS and a
  certificate for one name: `verify-full` reaches `required_ok` through both drivers, a name the
  certificate does not carry is refused, and backup-before-drop dumps through the tunnel.

### Fixed

- **The REST API answers in JSON whatever the client's `Accept` header says.** Exceptions render
  their code and status only for a request that expects JSON, so a client that sent no `Accept:
  application/json` got a 500 for a refusal (`not_authorized`, `assurance_required`, a 409 for an
  existing resource) and a 302 redirect for a validation error. `ApiGuard` now sets the header on
  every API request.
- **`doctor` judged a tunnelled server by the tunnel's local end.** The pooler check compared the
  server's own port with the admin connection's, which through an SSH tunnel is the tunnel's local
  port: every tunnelled server read as relayed, and PgBouncer behind a bastion was never warned
  about. The hosting check saw `127.0.0.1` instead of the database's host, so a managed provider
  behind a bastion was never recognised. Both now read the endpoint the tunnel forwards to, through
  `ServerRegistry::databasePort()` and `databaseHost()`.
- **A session the database ended no longer hangs its client through a phpseclib tunnel.** When the
  database closed a session (an idle timeout, `pg_terminate_backend`, a restart), the SSH server
  sent CHANNEL_EOF, which phpseclib drops without a trace, so the pump never passed it on. libpq,
  having read the FATAL error, then waited forever for the end of the stream, and the request
  hung. The pump now shuts its write side to that client, as OpenSSH's forwarding does, and the
  client fails at once. The OpenSSH driver was not affected.
- **A session closed by the server no longer stalls the others through a phpseclib tunnel.**
  phpseclib raises its read timeout to 5 seconds when it handles one channel's close while
  reading another, so a poll of an idle session could block the whole forwarding process and
  every session through it. `ForwardingSsh2` clamps that timeout to the poll's own while it polls.

## [0.1.3] - 2026-10-18

### Changed

- **A Warning or Critical event's notification keeps its category's channels and adds
  `alerts.channel`**, each channel once. Since 0.1.1 `alerts.channel` replaced them, so setting it
  to `slack` silently stopped the security mail for adoptions and suspicious activity.
- **laranail/db-tools `^0.1.7`** (was `^0.1.6`) in `require-dev` and `suggest`. 0.1.7 dumps a
  Postgres server reached through an SSH tunnel (`host;hostaddr=`, the form db-console writes for a
  tunnelled server) with `sslmode=verify-full`, and a MySQL server reached through a unix socket,
  including one described by its socket alone. With 0.1.6, backup-before-drop on those servers fails,
  and since 0.1.1 a failed backup stops the drop.
- **The 0.1.1 upgrade note on backups through a PostgreSQL tunnel** now points at db-tools 0.1.7,
  which ends the `verify-full` workaround, and says "three things" over its three items.

### Fixed

- **A create db-console half-made is reported as the failure it is.** Since 0.1.2, a create that
  failed while the resource then existed was treated as a lost race and refused with a 409, or
  adopted under `adopt`. That also caught db-console's own half-made resources: SQL Server's
  `CREATE LOGIN` succeeding and `CREATE USER` failing, or the second statement of a Postgres
  database create. Only a failure of the create's first statement is a race now
  (`AdminConnection::runCreate()`); a later one fails as it did before 0.1.2.

## [0.1.2] - 2026-10-17

### Removed

Each had a replacement and announced itself when used, through 0.1.1. See UPGRADING for what a
host still using one sees.

- **The 22 bare gate abilities `db-console.<permission>`**, with `ConsolePermission::deprecatedAbility()`,
  `ConsolePermission::DEPRECATED_ABILITY_PREFIX` and `Authorization\DeprecatedAbilities`. Use
  `laranail-db-console.<permission>` (`ConsolePermission::ability()`).
- **`ConsolePermission::fromAbility()` no longer reads the old `db-console.` prefix.** Stored rows
  are renamed by a migration instead (see Added), so nothing reads two names at runtime.
- **Command `db-console:install`** (`Console\Commands\DeprecatedInstallCommand`). Use
  `laranail::db-console.install`.
- **Config fallbacks `api.guard` and `api.prefix`**, with `Http\Api\DeprecatedConfigKeys`. Use
  `api.auth` and `api.path`; doctor reports the old keys.
- **`Exceptions\ConfirmationRequired` and `ExceptionCode::ConfirmationRequired`**
  (`confirmation.required`). `AssuranceRequired` replaced them in #49.

### Added

- **`api.auth_failure_limit`** (default `10,1`): an IP that fails to authenticate that many times
  in the window is answered 429, with `Retry-After`, until the window ends. `api.rate_limit` is
  keyed on the authenticated caller, so failed authentication was never throttled.
- `Authorizer::authorize()`, `Authorizer::assure()` and `AssuranceEnforcer::enforce()` take an
  optional last `$alsoRequire` list of `Assurance`, added to what `security.assurance` asks of the
  permission and checked in the same pass.
- **A Managed databases page and a DigitalOcean recipe** (`docs/managed-databases.md`, `docs/recipes/digitalocean.md`), which bring the hosting profile, TLS, the least-privilege admin, `doctor`'s checks and backups together for a provider-managed server. Linked from the servers, doctor, backups and engines pages and the README.
- **Catalog migration `0001_01_01_001100_migrate_db_console_permission_names`.** Renames permission
  rows stored under `db-console.*` to `laranail-db-console.*`, merging into a scoped row install
  already seeded so no role loses a permission, and gives the shipped Owner `resource.adopt`
  without re-syncing any role. Builtin and Spatie drivers; idempotent.
- **`Doctor\ConfigCheck`**: `config:legacy-api-keys` fails a config that still sets `api.guard` or
  `api.prefix`, and `config:on-existing` warns when existing resources are adopted by default,
  globally or for a server.

- **`laranail::db-console.user:grants` and `GET …/servers/{server}/accounts/{username}/grants`.**
  `PrivilegeManager::showGrants()` had no caller, so no command or route showed what an account may
  do. Both take a host and a direct-only switch, are gated by `account.view`, and print or return
  `Services\Results\GrantEntry` values (`PrivilegeManager::grants()`); `showGrants()` keeps
  returning one line per entry.
- **`doctor` warns about login roles a PostgreSQL 16+ admin cannot administer.** From 16,
  `CREATEROLE` covers only the roles the admin holds ADMIN OPTION on; dropping or changing any other
  failed only when tried. `server:<name>:role-admin` names them (up to ten) with the grant that
  hands one over (`AdminInspectingEngine::unmanageableRoles()`).
- **A `C` collation for PostgreSQL** (`Enums\Collation::C`, offered with `sql_ascii`). `C` and
  `POSIX` create the database in that locale, `UTF8` included.
- `Engines\PasswordConstrainedEngine` and `AccountManager::assertAcceptsPassword()`;
  `Engines\ServerVersionAwareEngine`.
- A `live-pgsql` leg on a PostgreSQL 17 cluster initialised with the ICU locale provider.

### Changed

- **The PostgreSQL grant listing shows effective privileges, and says where each comes from.** It
  listed explicit grants only, so a role's own tables, schema `public` on PostgreSQL 14 (held
  through PUBLIC) and anything held through a group role did not appear. A NULL ACL is now read as
  the default one (`acldefault()`), inherited roles' entries are included, and each line ends
  `via owner`, `via PUBLIC` or `via <role>` unless the privilege is the role's own. System schemas
  and template databases are left out. Default-privilege lines name the creating role
  (`... for role <name>`).
- **`doctor` tells a pooler by the port the server listens on.** It compared the configured port
  against a list, which warned about a direct connection on 6432 and missed a pooler on any other
  port. A known pooler port the server itself listens on is no longer warned about; a connection
  relayed from any other port is reported as information.
- **`adopt_and_apply` is decided by the assurance policy.** It still needs `account.rotate`, which
  the assurance policy (`security.assurance`) now checks with a recent confirmation always required,
  whatever `assurance.recent_confirmation` lists. So it follows the same enforcement mode,
  break-glass and `AssuranceRequired` (HTTP 403, `assurance_required`) as every other permission,
  and a shell run with no operator is left to the gate. It dispatches `AccountPasswordApplied`. For
  a database it adopts.
- **Every adoption refusal is reported, not only the policy's**: an operator who asks to adopt
  without `resource.adopt`, or to apply a password without `account.rotate` or the assurance, also
  dispatches `AccountCreationRefused` / `DatabaseCreationRefused`, with `reason` (`policy`,
  `not_authorized`, `assurance_required`) and `requested` in its context.
- One `Support\DriverBoolean::truthy()` reads a driver's boolean column (Postgres `bool`, MySQL `1/0`,
  `'t'/'f'`); four services each had their own copy.

### Fixed

- **A Postgres database holding only a function, a procedure or a schema read as empty**, so a wizard
  rollback dropped it with them. The emptiness check counts relations, functions and procedures, and
  schemas other than `public`, leaving out what an extension created.
- `OperatorAssurance` was bound to its null default twice; it is bound once, in the operator-security
  registration.
- **A create that loses a race to another process is handled by the existing-resource policy.**
  An account or database created between db-console's existence check and its create used to fail
  with the driver's duplicate error; it is now refused with a 409, or adopted when the call asked.

- **The wizard created a database before refusing the password.** A password PostgreSQL would
  rewrite (SASLprep) was refused when the account step built its statement, after the database
  existed; the rollback dropped it again. The wizard now asks the engine first, and `user:create`
  and the API refuse it before the existing-account check.
- **A non-UTF8 PostgreSQL database could not be created on an ICU cluster.** `template0` is ICU
  there, which has no `SQL_ASCII`. From PostgreSQL 15 the statement asks for
  `LOCALE_PROVIDER libc`; `DatabaseManager::create()` reads the server version first, since older
  servers reject the option.
- **A collation was silently ignored on PostgreSQL.** One that is neither a MySQL collation name nor
  `C`/`POSIX` is now refused with `UnsupportedOperation`.

## [0.1.1] - 2026-10-17

### Added

- **A hosting profile per server.** `servers.<name>.hosting` takes `self_hosted` (the default),
  `digitalocean`, `rds`, `cloud_sql` or `generic`. A managed hosting:
  - hides the provider's and the engine's own accounts from `AccountManager::list()`;
  - defaults a new MySQL-family account's host to `%` (`ServerRegistry::defaultAccountHost()`, used
    by `user:create`);
  - treats the provider's point-in-time recovery as the backup before a drop (see Changed).

  `ServerRegistry::capabilities()` merges the profile into the engine's `Capabilities`. An unknown
  value fails instead of becoming self-hosted. New: `Enums\Hosting`, `Domain\HostingProfile`.
- **Doctor checks what a managed server enforces** (`Doctor\HostingCheck`):
  - a self-hosted server whose host is DigitalOcean's or RDS's;
  - a MySQL admin that cannot read `mysql.user`;
  - `sql_require_primary_key` and `gtid_mode`;
  - a Postgres admin without `pg_signal_backend`, which a forced drop needs.
- **Existing accounts and databases are refused and reported unless an operator adopts them**
  (owner decision 2026-10-17). `AccountManager::create()` and `DatabaseManager::create()` used to
  adopt a resource that was already there without saying so, and dropped the account's supplied
  password. Both now go through `Services\ExistingResourceGuard` and an `ExistingResourcePolicy`
  (`refuse`, `adopt`, `adopt_and_apply`) resolved per call (a new optional last parameter), then
  `servers.<name>.accounts|databases.on_existing`, then `accounts.on_existing` /
  `databases.on_existing`, defaulting to `refuse`. A configured value that is not a policy refuses.
  - `refuse` throws `AccountAlreadyExists` / `DatabaseAlreadyExists` (HTTP 409, codes
    `account.already_exists` / `database.already_exists`) naming the options the caller may
    resubmit with, and dispatches `AccountCreationRefused` / `DatabaseCreationRefused`.
  - `adopt` needs the new **`resource.adopt`** permission (Owner only by default), records the
    resource in the catalog with its password untouched, and dispatches `AccountAdopted` /
    `DatabaseAdopted` (Warning, so they alert).
  - `adopt_and_apply` also needs `account.rotate` and an identity confirmation within
    `security.step_up.ttl` seconds (default 300), read from the new `Contracts\OperatorAssurance`
    that the host binds (`hasSecondFactor()`, `recentlyConfirmedAt()`). Authentication stays with
    the host. Until it binds one, `NullOperatorAssurance` reports none, so `adopt_and_apply` is
    refused with `ConfirmationRequired` (HTTP 403, `confirmation.required`). It dispatches
    `AccountPasswordApplied`. For a database it adopts.
  - Results carry `adopted` and `password_applied`. All five events are Security-category
    notifications (`ExistingResourceNotification`) and webhook events.
- **`ProvisioningWizard::provision()` takes `$onExisting`.** Without a password it never replaces an
  adopted account's credential. With one, it checks `account.rotate` and the confirmation when it adopts the
  account, before the grant, and applies the password as its last step, so an earlier failure leaves
  the credential as it was.
- **`--on-existing`** on `user:create`, `db:create` and `wizard`. Interactive and unset, they ask
  what should happen to a resource that is already there, naming each option's risk, defaulting to
  the configured policy. **`on_existing`** on the API's create endpoints; adopted resources answer
  200, new ones 201.
- **Slack and database notification channels** alongside mail, chosen per category by
  `notifications.channels`; Slack goes to `notifications.slack.route` (suggests
  `laravel/slack-notification-channel`), the in-app inbox to `notifications.database.users`.
- **`api.rate_limit` is applied** as the `laranail-db-console.api` named limiter, keyed on the API
  caller (`'requests,minutes'`; empty or `0` switches it off).
- `Http\Api\ApiSettings`, the API's settings read from the documented keys.
- **Operator assurance, answered by the host.** `Contracts\OperatorAssurance`
  (`hasSecondFactor()`, `recentlyConfirmedAt()`) is how the host's authentication tells db-console
  what it knows about an operator; db-console verifies no codes and owns no enrolment.
  `Security\NullOperatorAssurance` is the default binding, and
  `Security\SessionConfirmationAssurance` reads Laravel's `auth.password_confirmed_at` for hosts
  that want a starting point.
- **Assurance is enforced on top of RBAC** at `Services\Access\Authorizer`, the single
  authorization entry point, so the API, the WebUI and CLI runs that carry an operator are all
  covered. By default writes need a second factor, and dropping a database or an account,
  rotating a password, changing an account's host and rotating secrets also need a confirmation
  within `security.step_up.ttl` (300 s). Refusals are `AssuranceRequired` (HTTP 403,
  `assurance_required`, with a `missing` list) and are audited as `AssuranceShortfall`.
- `security.assurance.enforce`: `off`, `report` or `require`. Unset, it is `require` in
  production and `report` elsewhere. `report` audits each shortfall and alerts once a day per
  operator.
- **Break-glass**: `laranail::db-console.security.recover <user>`, shell only, lifts db-console's
  assurance requirements for 15 minutes and raises a critical `BreakGlassIssued` alert.
- **The last Owner cannot be stranded**: `role:revoke` refuses to take global Owner from the last
  holder, or (with enforcement on) the last one with a second factor, and
  `Security\OwnerSafety::assertMayLoseSecondFactor()` lets the host refuse the same before it
  disables one (`OwnerWouldBeStranded`, HTTP 409).
- `doctor` reports `security:assurance` (an error when off in production),
  `security:assurance-binding` (no host binding) and `security:owners`.
- `ConsolePermission::isRead()`, the `security.assurance` and `security.break_glass` operation
  types, and the `security_bypasses` catalog table.
- See [Operator assurance](docs/tools/operator-assurance.md) and
  [Implement OperatorAssurance](docs/recipes/implement-operator-assurance.md).
- **SSH tunnels to database servers** (Phase D4). A server's `ssh` block routes its admin
  connection through a tunnel, behind the `Tunnels\Contracts\TunnelDriver` contract with two
  drivers chosen per server: `openssh` (the default; `ssh -N -L` with `BatchMode`,
  `StrictHostKeyChecking=yes` against a generated `known_hosts`, `ExitOnForwardFailure` and
  `ServerAliveInterval`) and `phpseclib` (pure PHP, forwarding from a child process). Both refuse
  a host key that is not the pinned `ssh.host_key`, and there is no trust on first use. A private
  key comes from `ssh.key` (a path) or `ssh.key_secret` (a secrets-driver reference written to a
  `0600` file in a `0700` directory and removed with the tunnel). `ServerRegistry` opens the tunnel
  on first use, rewrites the admin connection to its local end, reopens it if it dies, and
  `closeTunnels()` stops it and restores the connection when the application terminates.
- **Sockets in both directions.** `ssh.remote_socket` forwards to a Unix socket on the SSH host
  (OpenSSH `-L port:/socket`, phpseclib `direct-streamlocal@openssh.com`), and `ssh.local=socket`
  makes the tunnel's local end a `0600` socket in a `0700` directory instead of a loopback port.
  PostgreSQL's local socket is named `.s.PGSQL.5432`, where libpq looks for it.
- **TLS through a tunnel.** The `tls` block still applies end to end. PostgreSQL with
  `sslmode=verify-full` dials the forward through libpq's `hostaddr` and keeps verifying the
  certificate against the real host name.
- `doctor` checks `server:<name>:ssh-driver`, `ssh-host-key` (with the pinned fingerprint) and
  `ssh-tunnel`, before the reachability round trip, which then runs through the tunnel.
- `TunnelFailed` (`server.tunnel_failed`, HTTP 502) and the `TunnelDriverType` enum.
- **A live SSH job** (`live-ssh` in `tests.yml`): an OpenSSH server container in front of
  PostgreSQL 18 and MySQL 8.4, sharing their socket directories, running both drivers over TCP and
  remote sockets, a socket local end, two concurrent sessions through one tunnel, TLS measured
  inside the tunnel, `doctor`, and an unrelated host key that must be refused.
- `phpseclib/phpseclib` `^4.0.1` as a suggestion, for the phpseclib driver.

- **The `live-tls` job runs servers that enforce TLS**, as DigitalOcean's managed MySQL and
  PostgreSQL do, so doctor's TLS findings are proven against a server that refuses plaintext and
  not only one that offers TLS. MySQL 8.4 runs with `--require-secure-transport=ON` and PostgreSQL
  18 with `ssl=on` and a `pg_hba` that rejects every `hostnossl` line, both serving a certificate
  from a CA generated in the job, both reached as restricted admins (the minimal MySQL grant, a
  `CREATEDB CREATEROLE NOSUPERUSER` role) through a host name doctor does not exempt as local. The
  `live-tls` group asserts that a plaintext session is refused and is a TLS error, that
  `verify=false` is an Unverified warning, and that the CA with `verify=true` is green. On
  PostgreSQL, `tls.enabled=false` is still encrypted, because libpq's default `sslmode=prefer`
  takes the TLS the server offers, and doctor warns that nothing requires it; a plaintext session
  there needs `sslmode=disable`. A plaintext
  MySQL and PostgreSQL run beside them, and pointing `tls.enabled=true` at either turns doctor red.
  Every refusal is paired with a connection the same server accepts, so a server that is down fails
  the group instead of passing it. `LiveTlsWorkflowTest` pins each copy of the minimal grant in the
  workflow to `Doctor\MinimalAdminGrant`.
- **A live provisioning job against MySQL 8.4 and 9 and MariaDB 11.4 and latest** (`live-mysql` in
  `tests.yml`). 8.4 is the only MySQL DigitalOcean offers. It runs the `live-mysql` group as an
  admin holding exactly the grant doctor recommends, never root, with `sql_require_primary_key=ON`
  on MySQL: provision through the wizard with the default `utf8mb4_unicode_ci`, migrate and write as
  the new account, grant every preset privilege, revoke, move an account to a new host, drop an
  account and a database, check emptiness, and roll the wizard back. `MinimalAdminGrantTest` keeps
  the job's grant, the README and the recipe the same as `Doctor\MinimalAdminGrant`.
- `Doctor\MinimalAdminGrant::statements()`, the recommended admin per engine, and
  `MySqlEngine::grantablePrivileges()`, the privilege keywords it builds the grant from.
- **A live provisioning job against PostgreSQL 14 to 18** (`live-pgsql` in `tests.yml`), plus the
  19 release candidate as a leg whose failures do not fail the run. 18 is the latest release, 15
  to 17 the three before it, and 14 the oldest the project still supports. It runs the
  `live-pgsql` group as an admin holding only `CREATEDB CREATEROLE`, the shape of a managed
  service's admin user: provision, migrate as the new role, read a table created after the grant,
  drop the role while it still owns objects, drop a database with a session open, check
  emptiness, and fail a drop and a grant part way. The engine tests compare SQL strings, which is
  how every Postgres defect below passed.
- **Statements can be scoped to a database** (`Statement::inDatabase()`, `Statement::$database`),
  and `Servers\ScopedConnections` runs each one on a short-lived connection to its database, built
  from the admin connection's own configuration. `AdminConnection::run()` refuses a statement
  scoped to a database it is not on, so a grant can no longer land in the wrong database quietly.
- `Engines\OwnershipReleasingEngine`, `Engines\EmptinessProbingEngine` and
  `Engines\GrantVerifyingEngine`, implemented by `PostgresEngine`, following the `HostScopingEngine`
  pattern for dialect only one engine needs.
- `ScopedConnections::transaction()` runs work in a transaction on every connection it touches,
  through a `ScopedSession` that can checkpoint with `commit()`. For engines with transactional DDL.
- `Services\OwnershipReleasingDrop`, which drops a Postgres role so that a failure leaves as little
  behind as Postgres allows (see Fixed).
- The `grant.not_applied` exception code (`GrantNotApplied`, HTTP 502).
- **`doctor` checks a Postgres admin.** It skipped Postgres and reported every admin as fine, a
  superuser included. It now reads the role from `pg_roles` (`Engines\AdminInspectingEngine`,
  judged by `Doctor\PostgresAdminCheck`) and fails a superuser, an admin without `CREATEDB` or
  `CREATEROLE`, and a server older than 13, and warns when the admin connects through a known
  pooler port (6432, 6543, 25061).
- `Engines\DatabaseScopedGrantsEngine`, `Engines\ScramSha256` and `ScopedConnections::select()`,
  which reads rows from each statement's own database.
- **`ConsolePermission::fromAbility()`** reads a gate ability or stored permission name in either
  form, and **`deprecatedAbility()`** names the pre-0.1 ability. Both RBAC drivers read stored names
  through `fromAbility()`.
- **`tests/Feature/NamingConventionTest.php`** reads the live gate, Artisan and middleware
  registries through package-tools' `AssertsRegisteredNames`, and checks every deprecated alias
  answers as its replacement and announces itself once.

- **`assertNoNullOnlyOptionGuards()` is enforced over `src/`.**

### Changed

- `laranail/db-tools` (dev, suggested) is required at `^0.1.6`, whose backups honour `url`, pass TLS
  to the dump client and take a consistent dump on managed servers.
- The `live-mysql` job runs MySQL with GTID on, as DigitalOcean does, installs each family's own dump
  client, and drops a non-empty database with backups on as the recommended admin, then restores the
  dump.
- On a server with a managed hosting, the drop no longer dumps the database first. It logs
  `database.backup.provider` with a `restore_point` timestamp to restore from instead.
  `backup.dump_on_managed=true` (env `DB_CONSOLE_BACKUP_DUMP_ON_MANAGED`) keeps dumping.
  Self-hosted servers, the default, are unaffected.
- **Creating an account or database that already exists now fails with a 409** instead of
  succeeding with `alreadyExisted`. See UPGRADING.
- **`resource.adopt` is the 22nd console permission.** Owner holds it; Admin, Operator, ReadOnly and
  Auditor do not.
- **Notifications are queued** (`notifications.queue`, default `true`): `DBConsoleNotification` now
  implements `ShouldQueue`. Set `notifications.queue` to `false` to send in the request.
- **A Warning or Critical event's notification goes out on `alerts.channel`** (default `mail`, as
  before) rather than its category's channels.
- `DBConsoleNotification::toArray()` adds `severity` and `subject`.
- `DbConsoleCatalog::recordDatabase()` with an empty charset leaves a recorded charset alone. An
  adopted database is recorded without one, since db-console did not choose it.

- **The API guard makes its configured guard the request's default** (`Auth::shouldUse()`), so the
  gate, the audit actor and the assurance check resolve the API caller rather than the default
  web guard's user.
- `Authorizer`, `SecretRotator`, `ApiGuard` and `DoctorService` take new constructor arguments
  (`AssuranceEnforcer`, `Authorizer`, `AuthFactory`, `OperatorSecurityCheck`), resolved from the
  container.
- `laravel/framework ^13.0` is now declared in `require`. `src/` uses `Dispatchable`, `FormRequest` from `Illuminate\Foundation`, which no `illuminate/*` component ships, so the dependency only arrived through the host application.
- The base `DBConsoleCommand` applies `laranail/package-tools`' `Commands\Concerns\ReadsOptions`;
  its own `--server` resolution and `ServerAddCommand`'s `--host`/`--port` handling were the same
  normalisation written out by hand and now use the accessors.

- **`DbCreateCommand` no longer declares a private `stringOption()`.** It was the trait's method
  reimplemented, and once the base applied the trait PHP refused the class outright -- a private
  method cannot narrow a protected one. Removing it is behaviour-preserving: both return `''` for
  a non-string value.

- **The gate abilities and the install command are vendor-scoped.** The 21 abilities are
  `laranail-db-console.<permission>` (was `db-console.<permission>`), and the install command is
  `laranail::db-console.install` (was `db-console:install`). Both lived in flat, host-owned
  registries, where a sibling package or the application claiming the same key silently replaces
  it. `ConsolePermission::ability()` returns the scoped name, so the audit trail records it as the
  target of a denied action, and `install` seeds permission rows under it. Rows stored before the
  rename keep their names and still resolve. Requires `laranail/package-tools ^0.1.3`.

### Deprecated

- **Config keys `api.guard` and `api.prefix`.** Read only when `api.auth` / `api.path` is unset,
  with one `E_USER_DEPRECATED` notice each. Removed no earlier than the next minor after 0.1.

- **Gate abilities `db-console.<permission>` (21).** Each is still defined and asks the gate for its
  `laranail-db-console.*` ability, so a host's definition or before/after callback for the scoped
  name governs the old one too. The first check of each raises one `E_USER_DEPRECATED` notice.
- **Command `db-console:install`.** Still registered, hidden; it prints one line naming
  `laranail::db-console.install`, then runs it and returns its exit code.

Both are removed no earlier than the next minor after 0.1.

### Fixed

- **`MySqlEngine::createAccountWithAuth()` spliced its hash literal into `CREATE USER` unchecked.**
  It now refuses anything that is not a `0x` literal of whole hex bytes, as `MariaDbEngine` already
  did; the two share the check.
- **A MySQL database holding only a routine, an event or a trigger read as empty**, so a wizard
  rollback dropped it with them. The MySQL family now implements `EmptinessProbingEngine` and counts
  `TABLES`, `ROUTINES`, `EVENTS` and `TRIGGERS`.
- **Postgres grants covered schema `public` only.** Grants, revokes and the privilege check now
  cover every user schema the admin can grant on, and default privileges are database-wide (no
  `IN SCHEMA`) with `ON SCHEMAS`, so schemas and objects created later are covered. Revokes also
  clear the per-schema defaults earlier releases set. A schema owned by a superuser is skipped.
- **A Postgres reader missed tables other roles created later.** A default privilege belongs to the
  role that creates the object. A grant now sets defaults `FOR ROLE` each role that can create
  objects in the database. A writer granted after the readers inherits the admin's database-wide
  defaults, which list every role db-console granted there.
- **Granting on objects another role owns failed or did nothing.** `RoleMembership` takes membership
  in the roles `OwnerDelegatingEngine::rolesToAssume()` lists for the duration of the grant or
  revoke, and gives it back afterwards. A role the admin cannot take is reported in the result
  (`not_covered`) and logged.
- **A Postgres database with an application role's session open could not be dropped.**
  `WITH (FORCE)` ends only sessions the admin may end, and a CREATEROLE admin on PG16+ does not
  inherit the roles it creates. The drop now reads whose sessions they are (`SessionEndingEngine`),
  takes membership so `FORCE` can end them, and refuses up front, before the backup runs, when a
  superuser or a role the admin cannot act as holds one.
- **The REST API could not serve an authenticated request.** `ApiGuard` bound a user resolver that
  called itself, so every authenticated request recursed until the stack ran out. It also never made
  the API guard the default, so the services' Gate saw a sanctum caller as nobody. It now binds the
  user it resolved and calls `shouldUse()`, as Laravel's `Authenticate` middleware does.
- **`api.auth` and `api.path` were ignored.** The code read `api.guard` and `api.prefix`, which the
  config file never defined, so `DB_CONSOLE_API_AUTH` and `DB_CONSOLE_API_PATH` did nothing.
- **`api.require_https`, `api.confirm_destructive`, `notifications.queue`,
  `notifications.quiet_hours` and `alerts.channel` were configured and never read.** All five are
  read now; the API defaults are unchanged.

- **A PostgreSQL server connected over its Unix socket** (`host` a directory) was classed as
  remote, so `doctor` reported "TLS configured but plaintext" for a connection that never leaves
  the host. Socket connections are now local, MySQL's `unix_socket` included.
- **Backup before drop ignored a MySQL `unix_socket`.** db-tools reads `socket`, so `mysqldump`
  connected by host and port instead. The backup connection now carries both keys.
- **Backup before drop resolves the server first**, so a server's SSH tunnel is open and its
  admin connection points at it before the dump reads that connection.
- **A failed backup-before-drop still dropped the database.** `DbToolsBackupService` logged the
  failure and returned, so the drop it was meant to make recoverable ran anyway. It now throws the new
  `BackupFailed` (code `backup.failed`), the drop does not run, and the failure is audited like any
  other failed drop. `backup.abort_drop_on_failure=false` restores the old log-and-drop.
- **Every backup of a database overwrote the last, and PostgreSQL archives were named `.sql`.**
  Snapshots are now `{database}-{UTC timestamp}.dump` (PostgreSQL) or `.sql`, so a restore picks
  `pg_restore` for a custom-format archive instead of replaying it through psql.
- **A `DATABASE_URL` admin connection backed up the url's database, not the target.** The url is now
  expanded before the target database is set, so it can no longer override it.
- **`backup.retention_days` was never read.** Snapshots of a database older than it are now pruned
  after each successful snapshot; `0` keeps them all.
- **A non-local backup disk dumped into the working directory.** `path()` on S3 returns the object
  key. The snapshot now fails, with the disk and driver named, unless the disk uses the `local` driver.

- **The `tls` block was never applied, and `doctor` reported TLS from config alone.** Nothing turned
  `tls.ca/cert/key/verify` into driver options, so an admin connection could run in plaintext while
  `TlsChecker` reported `required_ok` because `tls.enabled` was true. `ServerRegistry` now writes the
  block into the admin connection before connecting (PDO SSL options for MySQL/MariaDB; `sslmode`,
  `sslrootcert`, `sslcert`, `sslkey` for PostgreSQL; `encrypt`, `trust_server_certificate` for SQL
  Server), purging a connection already opened without it. `TlsChecker` asks the live session
  through the new `TlsProbe` contract (default `SessionTlsProbe`), so a plaintext session is `off`
  and fails `doctor` whatever the config says. A new `TlsStatus::Unmeasured` case covers TLS that
  is configured but could not be checked.

  **Behaviour change:** `TlsChecker::status()` now opens the admin connection to measure it, where
  it used to read config only; `encryption:status` therefore needs the server reachable to report
  anything but `unmeasured`. A PostgreSQL server without SSL now refuses a connection with
  `tls.enabled=true` (`sslmode=require`) instead of falling back to plaintext.

- **`TlsChecker::isLocal()` treated a connection configured only by `url` (`DATABASE_URL`) as
  localhost**, which exempted exactly the managed-database setups from the TLS check. It now parses
  the url and checks every `read`/`write` host.
- **The recommended MySQL/MariaDB admin could not grant the `app_standard` preset**, nor `full`:
  `GRANT` needs `GRANT OPTION` on each privilege it hands out, and the recommendation left out
  `CREATE TEMPORARY TABLES`, `LOCK TABLES`, `REFERENCES`, `CREATE ROUTINE`, `ALTER ROUTINE`, `EVENT`
  and `TRIGGER`, so provisioning failed at its grant step with `InsufficientPrivilege`. The
  recommendation now gives every privilege a preset can carry `WITH GRANT OPTION`, built from the
  engine's own privilege map, and drops `RELOAD`, which nothing db-console runs uses.
- **Changing an account's host failed on MariaDB.** The account is recreated from its stored hash
  with `IDENTIFIED WITH <plugin> AS 0x...`, and MariaDB's grammar takes only a quoted string after
  `AS`. `MariaDbEngine` now decodes the hash and quotes it, and refuses one that is not hex.
- **A failed provisioning wizard dropped an account or database it had not created.** The wizard
  adopts an account or database that already exists, and its rollback then dropped it with the rest
  -- a customer's existing login, if a later step failed. The rollback now undoes only what the run
  created, as its documentation said. This applies to every engine.
- **PostgreSQL could not create a database with the package's own default charset.** `utf8mb4`
  became `ENCODING 'UTF8MB4'`, which Postgres has no encoding for. `PostgresEngine` now maps MySQL
  charset names to Postgres encodings (`utf8mb4`/`utf8mb3` → `UTF8`, `ascii` → `SQL_ASCII`), so the
  wizard, CLI, API and WebUI defaults all work without each call site knowing the engine.
- **PostgreSQL grants landed in the admin's database, not the target.** `GRANT ... ON ALL TABLES IN
  SCHEMA public` acts on the connected database, which was the admin connection's, so the target
  received `CONNECT` and nothing else. Schema, table, sequence and function grants now run inside the
  target database, with `GRANT USAGE ON SCHEMA public` (and `CREATE` when the set has it: PG15
  revoked it from `PUBLIC`) and `ALTER DEFAULT PRIVILEGES`, so tables the admin creates later are
  covered too.
- **PostgreSQL put `CREATE` and `EXECUTE` in a table grant, and turned any privilege it did not
  recognise into `SELECT`.** Each privilege now goes on the object Postgres grants it on: `CREATE`
  on the schema, `EXECUTE` on functions, `CREATE TEMPORARY TABLES` as `TEMPORARY` on the database,
  and sequence `USAGE`/`SELECT` alongside `INSERT`/`UPDATE`/`SELECT`. `ALTER`, `DROP`, `INDEX`,
  `ALTER ROUTINE`, `LOCK TABLES` and `EVENT` grant nothing, since Postgres reserves the first four
  to an object's owner. An unclassified privilege throws `UnknownPrivilege`.
- **PostgreSQL drops failed whenever anything was attached.** `DROP DATABASE` now uses `WITH
  (FORCE)`, which ends the sessions the admin may terminate; one held by a role it cannot signal
  still blocks the drop. `DROP ROLE` is preceded by
  `REASSIGN OWNED BY ... TO CURRENT_USER` and `DROP OWNED BY ...` inside every database the role has
  objects or privileges in, read from `pg_shdepend`, after the admin takes membership in the role.
  A CREATEROLE admin on PG16+ does not inherit the roles it creates.
- **A Postgres role drop that failed part way left the admin a member of the role**, with its
  privileges, and could leave the role's objects in some databases reassigned. The drop now refuses
  before changing anything when a database the role has objects in is out of the admin's reach;
  takes membership only when the admin does not already have the role's privileges; releases
  ownership in one transaction per database and drops the role in another; and gives the
  membership back when a step fails, escalating as `RollbackFailed` if that fails too.
- **A Postgres grant could report success without granting.** When the admin holds a privilege
  without the grant option, as on a database or schema another role owns, `GRANT` warns, grants
  what it can and succeeds. A grant now runs in one transaction on the target database together
  with a check of the role's effective privileges, and anything missing rolls the whole grant back
  and throws `GrantNotApplied`, naming each privilege. Revokes run in one transaction too.
- **A drop whose emptiness check failed was not audited.** The check now runs inside the drop's
  failure handling, so it is logged and dispatches `OperationFailed` like any other failed drop.
- **PostgreSQL showed a role's grants in the admin's own database only.** `showGrants()` read
  `information_schema.role_table_grants` on the admin connection, so a role provisioned into any
  other database showed nothing, and schema and database grants never showed at all. Database
  grants are now read from `pg_database`, and schema, table, sequence, function and default-privilege
  grants inside every database the role has entries in. Each line is `<kind> <database> <object>
  <privilege>`; a database the admin cannot connect to is listed as not readable.
- **PostgreSQL could not create a `LATIN1` or `SQL_ASCII` database**, the two non-UTF8 encodings it
  offers: a copy of `template1` keeps its `UTF8` encoding. Any encoding other than `UTF8` now copies
  `template0` with the `C` locale. The `UTF8` path is unchanged.
- **PostgreSQL role passwords were sent in clear**, where `log_statement = 'ddl'` or a failed
  statement could write them to the server log. `CREATE ROLE` and `ALTER ROLE` now send a
  SCRAM-SHA-256 verifier computed client-side, which Postgres stores as given.
- **PostgreSQL's "is empty" check always answered "not empty".** It is now read inside the target
  database, so a wizard rollback drops the database it created instead of leaving it behind, and an
  empty database is no longer backed up before a drop.

- **`audit:view --limit=<non-numeric>` printed nothing.** `(int)` on a typo is `0`, and `limit(0)`
  returns no rows -- so a mistyped limit rendered an empty audit view, which reads exactly like
  "there is no audit history" rather than like a bad argument. Now `intOption('limit', 25)`, which
  falls back to the documented default.

- **A repeatable option containing an empty entry carried it through.** `--db=a --db=` produced
  `['a', '']`, and the empty string was passed on as a database, user, role, ability or event name.
  The six `(array)` casts are now `arrayOption()`, which trims and drops empties.

- **`docs/tools/commands.md` documented a `db-console:<command>` alias for every command, and
  `docs/tools/api.md` named the API middleware `db-console.api-guard`.** No command declares an
  alias, and the middleware is `laranail-db-console.api-guard`.

## [0.1.0] - 2026-07-11

Initial public release.

[Unreleased]: https://github.com/laranail/db-console/compare/v0.1.5...HEAD
[0.1.5]: https://github.com/laranail/db-console/compare/v0.1.4...v0.1.5
[0.1.4]: https://github.com/laranail/db-console/compare/v0.1.3...v0.1.4
[0.1.3]: https://github.com/laranail/db-console/compare/v0.1.2...v0.1.3
[0.1.2]: https://github.com/laranail/db-console/compare/v0.1.1...v0.1.2
[0.1.1]: https://github.com/laranail/db-console/compare/v0.1.0...v0.1.1

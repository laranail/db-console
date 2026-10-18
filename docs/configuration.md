# Configuration

Every `laranail.db-console.*` configuration key. Publish the file with `php artisan laranail::db-console.install` or `vendor:publish`.

## Catalog

| Key | Default | Purpose |
|---|---|---|
| `catalog.connection` | `db_console_catalog` | The dedicated catalog connection name. |
| `catalog.prefix` | `db_console_` | Table prefix for catalog tables. |
| `catalog.database` | `storage/db-console/catalog.sqlite` (env `DB_CONSOLE_CATALOG_PATH`) | The private SQLite file provisioned when `catalog.connection` names a connection the database config does not define. |

## Servers

`servers.{name}` defines a config-backed server: `engine`, `connection` (a `database.php` connection), and `tls` (`enabled`, `verify`, `ca`, `cert`, `key`). The `tls` block is written into that connection before it opens, and `doctor` measures whether the session is actually encrypted; see [Servers & connections](tools/servers.md#tls). `hosting` (env `DB_CONSOLE_PRIMARY_HOSTING`, default `self_hosted`) says where the server runs; see [Hosting](tools/servers.md#hosting). `default` (env `DB_CONSOLE_SERVER`) names the fallback server. `ssh` reaches the admin connection through a tunnel; see [SSH tunnels](tools/ssh-tunnels.md).

| Key | Default | Purpose |
|---|---|---|
| `servers.{name}.at_rest.show_status` | `true` | Whether to show the server's encryption-at-rest status. Read into `ServerDefinition::$showAtRestStatus`; nothing in db-console displays it yet, and db-console never enables or manages encryption at rest. |

## Accounts & databases

| Key | Default | Purpose |
|---|---|---|
| `accounts.default_host` | `localhost` | The host a new account is created for when none is given (`%` on a managed MySQL). |
| `accounts.password_min_length` | `16` | Minimum password length the API's password rule enforces. It can raise the floor, never lower it below 16. |
| `databases.default_charset` | `utf8mb4` | The charset `db:create` uses when `--charset` is not given. |
| `databases.default_collation` | `utf8mb4_unicode_ci` | The collation `db:create` uses when `--collation` is not given. |

## Backup

`backup.enabled`, `backup.before_drop`, `backup.abort_drop_on_failure` (a failed snapshot stops the drop), `backup.disk` (must use the `local` driver), `backup.retention_days` and `backup.dump_on_managed` (dump servers with a managed hosting too). See [Backup before drop](tools/backups.md).

## Secrets

`secrets.driver` selects the vault (`app_key` | `kms` | `vault` | `reference`). `app_key` is blocked in production without an explicit override. See [Secret vault](tools/secret-vault.md).

| Key | Default | Purpose |
|---|---|---|
| `secrets.allow_app_key_in_production` | `false` (env `DB_CONSOLE_ALLOW_APPKEY_IN_PROD`) | Let `app_key` run in production. Without it, boot refuses with `InsecureSecretDriver`. |
| `secrets.kms.provider` | `aws` (env `DB_CONSOLE_KMS_PROVIDER`) | `aws` or `gcp`. |
| `secrets.kms.key_id` | `null` (env `DB_CONSOLE_KMS_KEY_ID`) | The KMS key that wraps each data key. |
| `secrets.kms.region` | `null` (env `DB_CONSOLE_KMS_REGION`) | The KMS region; also the AWS Secrets Manager region for `reference`. |
| `secrets.vault.address` | `null` (env `DB_CONSOLE_VAULT_ADDR`) | The HashiCorp Vault address. |
| `secrets.vault.auth` | `approle` (env `DB_CONSOLE_VAULT_AUTH`) | `approle` or `token`. |
| `secrets.vault.role_id`, `secrets.vault.secret_id` | `null` (env `DB_CONSOLE_VAULT_ROLE_ID`, `DB_CONSOLE_VAULT_SECRET_ID`) | AppRole credentials. |
| `secrets.vault.token` | `null` (env `DB_CONSOLE_VAULT_TOKEN`) | The token for `token` auth. |
| `secrets.vault.mount` | `secret` (env `DB_CONSOLE_VAULT_MOUNT`) | The KV v2 mount. |
| `secrets.vault.path_prefix` | `db-console` (env `DB_CONSOLE_VAULT_PATH`) | The path under the mount secrets are written to. |
| `secrets.reference.provider` | `aws-secrets-manager` (env `DB_CONSOLE_SECRET_REF_PROVIDER`) | Where a `reference` pointer resolves: `aws-secrets-manager`, `vault` or `doppler`. |
| `secrets.reference.token` | `null` (env `DB_CONSOLE_DOPPLER_TOKEN`) | The Doppler service token, read when the provider is `doppler`. Without it every Doppler lookup fails as misconfigured. |

## Catalog encryption

| Key | Default | Purpose |
|---|---|---|
| `catalog_encryption.sqlcipher.enabled` | `false` (env `DB_CONSOLE_SQLCIPHER`) | Encrypt the whole SQLite catalog file with SQLCipher. Needs `pdo_sqlcipher`; without it db-console keeps column encryption. |
| `catalog_encryption.sqlcipher.key` | `null` (env `DB_CONSOLE_CIPHER_KEY`) | The SQLCipher key. |
| `catalog_encryption.encrypt_topology_columns` | `true` | Encrypt the topology columns (a server's `host`, an account's `username` and `host`) with the app key. `false` writes them in plaintext. Rows keep the form they were written in until saved again, and either form reads back. |

See [Catalog encryption](tools/catalog-encryption.md).

## RBAC

| Key | Default | Purpose |
|---|---|---|
| `rbac.driver` | `builtin` | `builtin` or `spatie`. |
| `rbac.user_model` | `App\Models\User` | The operator model. |
| `rbac.owner_user_id` | `null` | Bootstrap Owner assigned on install. |
| `rbac.seed_default_roles` | `true` | Seed the shipped roles on install. `false` leaves the role tables as they are, for a host that manages its own roles. |
| `rbac.teams.enabled` | `false` | Reserved for teams; `false` keeps the stub tables idle. |

## Existing resources

| Key | Default | Purpose |
|---|---|---|
| `accounts.on_existing` | `refuse` | `refuse`, `adopt` or `adopt_and_apply` for an account that is already there. |
| `databases.on_existing` | `refuse` | `refuse` or `adopt` for a database that is already there. |
| `servers.{name}.accounts.on_existing`, `servers.{name}.databases.on_existing` | unset | Per-server override. A call's own policy overrides both. |

See [Adopt an existing account or database](recipes/adopt-existing-resources.md).

## Operator assurance

| Key | Default | Purpose |
|---|---|---|
| `security.assurance.enforce` | unset: `require` in production, `report` elsewhere | `off`, `report` or `require`. |
| `security.assurance.second_factor` | `writes` | Which permissions need a second factor: `writes`, `all` or `none`. |
| `security.assurance.recent_confirmation` | drop database, drop account, rotate, change host, secrets | Permissions that need a confirmation within the ttl. |
| `security.step_up.ttl` | `300` | Seconds a confirmation counts as recent. |
| `security.break_glass_minutes` | `15` | How long a `security.recover` bypass lasts. |

The host binds `Contracts\OperatorAssurance`. See [Operator assurance](tools/operator-assurance.md).

## Notifications & alerts

| Key | Default | Purpose |
|---|---|---|
| `notifications.recipients.{category}` | security, destructive: `DB_CONSOLE_ALERT_MAIL` | Mail addresses per category. |
| `notifications.channels.{category}` | `['mail']` | Any of `mail`, `slack`, `database`. |
| `notifications.slack.route` | `DB_CONSOLE_SLACK_ROUTE` | A Slack webhook URL or bot channel. |
| `notifications.database.users` | `DB_CONSOLE_NOTIFY_USERS` | User ids whose in-app inbox receives notifications. |
| `notifications.quiet_hours` | `null` | Hold routine notifications in a window. |
| `notifications.queue` | `true` | `true`, a queue name, or `false` to send in the request. |
| `alerts.channel` | `mail` | The channels Warning and Critical events go out on. |
| `alerts.webhook` | `null` | Also post every alert here. |

See [Events & notifications](tools/events-notifications.md).

## Audit & logging

| Key | Default | Purpose |
|---|---|---|
| `audit.enabled` | `true` | Write the audit trail. |
| `audit.hash_chain` | `true` | Chain each audit row to the previous one's hash, so a removed or edited row is detectable. Rows written with it off carry no hash. |
| `logging.channel` | `db-console` (env `DB_CONSOLE_LOG_CHANNEL`) | The log channel db-console writes to. An app with no such channel gets its default logger. |

## API & webhooks

`api.enabled` (default `false`), `api.auth` (`sanctum` | `passport`), `api.path`, `api.allowed_ips`, `api.rate_limit` (`'requests,minutes'`), `api.auth_failure_limit` (`'failures,minutes'` per IP, default `10,1`), `api.require_https`, `api.confirm_destructive`. `api.guard` and `api.prefix`, read until 0.1.1, are no longer read; `doctor` reports them (`config:legacy-api-keys`). `webhooks.enabled`, `webhooks.sign_with` (`sha256`), `webhooks.max_attempts`, `webhooks.timeout`. `webhooks.queue` (default `true`): deliveries, and their retries, are always queued; a queue name sends them to that queue, and `true` leaves them on the default one. See [REST API](tools/api.md) and [Webhooks](tools/webhooks.md).

## Privilege presets

`presets` (default `[]`) names custom privilege presets, `'name' => ['SELECT', 'SHOW VIEW']`, checked against the privilege allow-list at boot. A forbidden or unknown privilege, an empty list, or a name a shipped preset uses fails the boot with `PresetMisconfigured` (`preset.misconfigured`), naming the preset. Nothing grants a custom preset by name yet; grants take the shipped `PrivilegePreset` cases or a `custom` privilege list.

---

[← Docs index](../README.md#documentation)

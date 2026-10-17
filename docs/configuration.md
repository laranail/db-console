# Configuration

Every `laranail.db-console.*` configuration key. Publish the file with `php artisan laranail::db-console.install` or `vendor:publish`.

## Catalog

| Key | Default | Purpose |
|---|---|---|
| `catalog.connection` | `db_console_catalog` | The dedicated catalog connection name. |
| `catalog.prefix` | `db_console_` | Table prefix for catalog tables. |

## Servers

`servers.{name}` defines a config-backed server: `engine`, `connection` (a `database.php` connection), and `tls` (`enabled`, `verify`, `ca`, `cert`, `key`). The `tls` block is written into that connection before it opens, and `doctor` measures whether the session is actually encrypted; see [Servers & connections](tools/servers.md#tls). `default` (env `DB_CONSOLE_SERVER`) names the fallback server.

## Backup

`backup.enabled`, `backup.before_drop`, `backup.abort_drop_on_failure` (a failed snapshot stops the drop), `backup.disk` (must use the `local` driver) and `backup.retention_days`. See [Backup before drop](tools/backups.md).

## Secrets

`secrets.driver` selects the vault (`app_key` | `kms` | `vault` | `reference`). `app_key` is blocked in production without an explicit override. See [Secret vault](tools/secret-vault.md).

## RBAC

| Key | Default | Purpose |
|---|---|---|
| `rbac.driver` | `builtin` | `builtin` or `spatie`. |
| `rbac.user_model` | `App\Models\User` | The operator model. |
| `rbac.owner_user_id` | `null` | Bootstrap Owner assigned on install. |
| `rbac.seed_default_roles` | `true` | Seed the shipped roles on install. |

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

`notifications.recipients.{category}` routes notifications; `alerts.webhook` receives high-severity alerts.

## API & webhooks

`api.enabled` (default `false`), `api.guard` (`sanctum` | `passport`), `api.prefix`, `api.allowed_ips`, `api.rate_limit`. `webhooks.enabled`, `webhooks.sign_with` (`sha256`), `webhooks.max_attempts`, `webhooks.timeout`. See [REST API](tools/api.md) and [Webhooks](tools/webhooks.md).

---

[← Docs index](../README.md#documentation)

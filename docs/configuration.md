# Configuration

Every `laranail.db-console.*` configuration key. Publish the file with `php artisan db-console:install` or `vendor:publish`.

## Catalog

| Key | Default | Purpose |
|---|---|---|
| `catalog.connection` | `db_console_catalog` | The dedicated catalog connection name. |
| `catalog.prefix` | `db_console_` | Table prefix for catalog tables. |

## Servers

`servers.{name}` defines a config-backed server: `engine`, `connection` (a `database.php` connection), and `tls` (`enabled`, `verify`, `ca`, `cert`, `key`). `default_server` names the fallback.

## Secrets

`secrets.driver` selects the vault (`app_key` | `kms` | `vault` | `reference`). `app_key` is blocked in production without an explicit override. See [Secret vault](tools/secret-vault.md).

## RBAC

| Key | Default | Purpose |
|---|---|---|
| `rbac.driver` | `builtin` | `builtin` or `spatie`. |
| `rbac.user_model` | `App\Models\User` | The operator model. |
| `rbac.owner_user_id` | `null` | Bootstrap Owner assigned on install. |
| `rbac.seed_default_roles` | `true` | Seed the shipped roles on install. |

## Notifications & alerts

`notifications.recipients.{category}` routes notifications; `alerts.webhook` receives high-severity alerts.

## API & webhooks

`api.enabled` (default `false`), `api.guard` (`sanctum` | `passport`), `api.prefix`, `api.allowed_ips`, `api.rate_limit`. `webhooks.enabled`, `webhooks.sign_with` (`sha256`), `webhooks.max_attempts`, `webhooks.timeout`. See [REST API](tools/api.md) and [Webhooks](tools/webhooks.md).

---

[← Docs index](../README.md#documentation)

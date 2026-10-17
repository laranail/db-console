# Configuration

Every `laranail.db-console.*` configuration key. Publish the file with `php artisan laranail::db-console.install` or `vendor:publish`.

## Catalog

| Key | Default | Purpose |
|---|---|---|
| `catalog.connection` | `db_console_catalog` | The dedicated catalog connection name. |
| `catalog.prefix` | `db_console_` | Table prefix for catalog tables. |

## Servers

`servers.{name}` defines a config-backed server: `engine`, `connection` (a `database.php` connection), and `tls` (`enabled`, `verify`, `ca`, `cert`, `key`). The `tls` block is written into that connection before it opens, and `doctor` measures whether the session is actually encrypted; see [Servers & connections](tools/servers.md#tls). `hosting` (env `DB_CONSOLE_PRIMARY_HOSTING`, default `self_hosted`) says where the server runs; see [Hosting](tools/servers.md#hosting). `default` (env `DB_CONSOLE_SERVER`) names the fallback server.

## Backup

`backup.enabled`, `backup.before_drop`, `backup.abort_drop_on_failure` (a failed snapshot stops the drop), `backup.disk` (must use the `local` driver), `backup.retention_days` and `backup.dump_on_managed` (dump servers with a managed hosting too). See [Backup before drop](tools/backups.md).

## Secrets

`secrets.driver` selects the vault (`app_key` | `kms` | `vault` | `reference`). `app_key` is blocked in production without an explicit override. See [Secret vault](tools/secret-vault.md).

## RBAC

| Key | Default | Purpose |
|---|---|---|
| `rbac.driver` | `builtin` | `builtin` or `spatie`. |
| `rbac.user_model` | `App\Models\User` | The operator model. |
| `rbac.owner_user_id` | `null` | Bootstrap Owner assigned on install. |
| `rbac.seed_default_roles` | `true` | Seed the shipped roles on install. |

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

## API & webhooks

`api.enabled` (default `false`), `api.auth` (`sanctum` | `passport`), `api.path`, `api.allowed_ips`, `api.rate_limit` (`'requests,minutes'`), `api.auth_failure_limit` (`'failures,minutes'` per IP, default `10,1`), `api.require_https`, `api.confirm_destructive`. `api.guard` and `api.prefix` are deprecated fallbacks for the first two. `webhooks.enabled`, `webhooks.sign_with` (`sha256`), `webhooks.max_attempts`, `webhooks.timeout`. See [REST API](tools/api.md) and [Webhooks](tools/webhooks.md).

---

[← Docs index](../README.md#documentation)

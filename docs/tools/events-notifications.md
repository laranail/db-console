# Events & notifications

Domain events drive the audit trail, logs, notifications, alerts, and webhooks.

## Overview

Every state change dispatches a domain event implementing `RecordsToAudit`. Listeners bound to that interface fan out: `WriteAuditLog` (the hash chain), `WriteChannelLog` (the dedicated log channel), `SendNotifications` (routed to `notifications.recipients` by category), `RaiseAlerts` (high-severity events to `alerts.webhook`), and `DeliverWebhooks` (external subscriptions). Because listeners bind to the interface, one registration covers every event. Notifications are opt-in — no recipients configured means nothing is sent.

## Categories and channels

Each notification has a category: `routine`, `destructive` or `security`. `notifications.channels.<category>` lists the channels it goes out on, any of `mail`, `slack` and `database` (default `mail`). Each channel needs a route, and one without is skipped:

| Channel | Route | Needs |
|---|---|---|
| `mail` | `notifications.recipients.<category>` | — |
| `slack` | `notifications.slack.route`: an incoming-webhook URL, or a channel name for a bot token | `laravel/slack-notification-channel` |
| `database` | `notifications.database.users`: ids of `rbac.user_model` users | `Notifiable` on the model, a `notifications` table |

A Warning or Critical event goes out on its category's channels and on `alerts.channel` too, each channel once, so loud events get their own route without losing their category's. `RaiseAlerts` also writes every alert to the log channel and posts it to `alerts.webhook` when set.

## Existing resources

A create that finds its account or database already there dispatches `AccountCreationRefused` / `DatabaseCreationRefused` (Notice) when it refuses, and `AccountAdopted` / `DatabaseAdopted` / `AccountPasswordApplied` (Warning) when an operator takes it over. All five are `security` notifications (`ExistingResourceNotification`), whatever their severity, and webhook events of the same names. See [Adopt an existing account or database](../recipes/adopt-existing-resources.md).

## Queue and quiet hours

Notifications implement `ShouldQueue`. `notifications.queue` is `true` (the default queue), a queue name, or `false` to send during the request. `notifications.quiet_hours` (`['start' => '22:00', 'end' => '07:00', 'timezone' => …]`, may span midnight) holds routine notifications until the window ends; destructive and security ones are never held.

---

[← Docs index](../../README.md#documentation)

# Events & notifications

Domain events drive the audit trail, logs, notifications, alerts, and webhooks.

## Overview

Every state change dispatches a domain event implementing `RecordsToAudit`. Listeners bound to that interface fan out: `WriteAuditLog` (the hash chain), `WriteChannelLog` (the dedicated log channel), `SendNotifications` (routed to `notifications.recipients` by category), `RaiseAlerts` (high-severity events to `alerts.webhook`), and `DeliverWebhooks` (external subscriptions). Because listeners bind to the interface, one registration covers every event. Notifications are opt-in — no recipients configured means nothing is sent.

---

[← Docs index](../../README.md#documentation)

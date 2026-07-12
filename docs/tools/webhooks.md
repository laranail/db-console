# Webhooks

HMAC-signed, secret-free delivery of domain events to external systems.

## Overview

Subscriptions listen to specific event types and optionally scope to one server. On each event, DBConsole builds a **secret-free** payload (the fact of what happened — event, server, target, outcome, time — never a password) and queues a delivery signed with an HMAC (`webhooks.sign_with`, default `sha256`) so the receiver can verify authenticity. Delivery is queued (a slow endpoint never blocks an operation), retries with exponential backoff, and auto-disables a subscription after `webhooks.max_attempts` failures (raising an alert). The signing secret is stored via the secret vault by reference and shown once on creation.

---

[← Docs index](../../README.md#documentation)

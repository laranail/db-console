# Comparison

Why `laranail/db-console` exists and how it differs from a raw database admin GUI or hand-run SQL.

## Versus a raw admin GUI (phpMyAdmin, Adminer, DBeaver)

Those are query tools: they hand you a SQL prompt and full trust. DBConsole is an operations tool: it exposes a fixed set of safe, audited operations, validates every input, caps privileges below server-wide, and never runs on a root account. It is meant to be handed to an app team, not just a DBA.

## Versus hand-run SQL

Hand-run SQL is unaudited, unvalidated, and easy to get subtly wrong (a too-broad grant, a forgotten host, a dropped-then-not-backed-up database). DBConsole makes the safe path the default: allow-list validation, least-privilege presets, backup-before-drop, compensating rollback, and a tamper-evident trail of who did what.

## Versus a bespoke internal tool

The security-critical parts — injection barriers, secret handling, RBAC, audit — are the parts that are easy to get wrong and expensive to get wrong. DBConsole ships them tested and reusable, headless, so you can put any UI (including the shipped [web UI](https://github.com/laranail/db-console-webui)) in front of the same audited core.

---

[← Docs index](../README.md#documentation)

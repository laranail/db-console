# REST API

An optional, off-by-default HTTP surface over the same services.

## Overview

The REST API is disabled by default (`api.enabled`) and mounts under `api.path` (default `api/db-console`). When enabled, every route sits behind the `laranail-db-console.api-guard` middleware: it enforces the API is enabled, the request is over HTTPS unless `api.require_https` is off (loopback is always allowed), the IP is allow-listed, and the caller is authenticated via the `api.auth` guard (`sanctum` or `passport`). The `laranail-db-console.api` rate limiter then applies `api.rate_limit` per caller. An IP that fails to authenticate `api.auth_failure_limit` times (default `10,1`: ten in a minute) is answered 429 with `Retry-After` until the window ends, before its credentials are looked at. **Authorization itself happens in the services** — the same Gate as the CLI and UI — so an out-of-scope caller gets a 403 identically. Destructive endpoints require a matching `confirm` field while `api.confirm_destructive` is on (the default). Every response is JSON, whatever the client's `Accept` header says: errors carry a `code` and the status it maps to, and validation errors are 422.

## Endpoints

Paths are under `api.path`. Each is gated by the permission shown, at the scope the service checks.

| Method and path | Request | Permission |
|---|---|---|
| `GET servers` | — | `server.view` at each server; the list holds only those the caller may see |
| `GET servers/{server}/databases` | — | `database.view` |
| `POST servers/{server}/databases` | `name`, `charset`, `collation`, `on_existing` | `database.create` |
| `DELETE servers/{server}/databases/{name}` | `confirm` (the name) | `database.drop` |
| `GET servers/{server}/accounts` | — | `account.view` |
| `POST servers/{server}/accounts` | `username`, `host`, `password` or `generate`, `on_existing` | `account.create` |
| `PATCH servers/{server}/accounts/{username}` | `host`, `new_host`, `rotate` | `account.edit` |
| `POST servers/{server}/accounts/{username}/password` | `host`, `password` or `generate` | `account.rotate` |
| `DELETE servers/{server}/accounts/{username}` | `host` (required), `confirm` (the username) | `account.drop` |
| `GET servers/{server}/accounts/{username}/grants` | `?host=`, `?direct=1` | `account.view` |
| `POST servers/{server}/grants` | `username`, `host`, `database`, `preset`, `privileges` | `grant.create` |
| `DELETE servers/{server}/grants` | `username`, `host`, `database`, `privileges` (default: the `full` preset) | `grant.revoke` |
| `POST servers/{server}/attachments` | `users` (`username`, `host`), `databases`, `preset`, `privileges` | `attach` |
| `DELETE servers/{server}/attachments` | `users`, `databases` (revokes the `full` preset) | `detach` |
| `POST tokens` | `user_id`, `name`, `abilities`, `expires_at` | `token.manage` |
| `POST role-assignments` | `user_id`, `role`, `scope` | `settings.manage` |
| `GET audit` | — | `audit.view` |
| `GET`, `POST webhooks`; `DELETE webhooks/{id}` | see [Webhooks](webhooks.md) | `webhook.manage` |

The service checks every write. The three reads with no service behind them (`GET servers`, `GET audit`, `GET webhooks`) are gated in their controllers.

A generated password is returned once, as `generated_password`. Attach and detach report every user and database pairing in `data` (`total`, `succeeded`, `failed`) and answer 207 when some failed, so a partial run is never reported as a success.

**Tokens** are capped twice: a token's abilities must be held by both the caller and the user it is issued to, so an Admin cannot mint a token carrying an Owner's abilities. The plain token is returned once. **Role assignment** grants privilege, so it needs `settings.manage`, which only Owner holds, plus any assurance `security.assurance` asks of it; a role that grants nothing is refused with 422 rather than stored.

## Existing resources

`POST …/accounts` and `POST …/databases` take an optional `on_existing` (`refuse`, `adopt`, `adopt_and_apply`). A resource that is already there and is refused answers 409 with `code` (`account.already_exists` or `database.already_exists`) and `options`, the values this caller may resubmit with. An adopted one answers 200, a new one 201. See [Adopt an existing account or database](../recipes/adopt-existing-resources.md).

## Grants

`GET …/servers/{server}/accounts/{username}/grants` lists what an account may do, read live and gated by `account.view`. `?host=` names the account's host (default `%`), `?direct=1` keeps what it holds itself and as an owner. Each entry in `data` has `kind`, `database`, `object`, `privilege`, `via` (null, `owner`, `PUBLIC` or a role), `readable` (false for a database the admin may not connect to) and `line`, the one-line form. An engine whose grants have no such structure (MySQL's `SHOW GRANTS`) fills `line` alone.

## Retired keys

`api.guard` and `api.prefix` were honoured as fallbacks for `api.auth` and `api.path` through 0.1.1. They are no longer read, and `doctor` fails a config that still sets them (`config:legacy-api-keys`), naming the key to rename it to.

## Operator assurance

An operation the caller has the permission for, but not the assurance (a second factor, a recent confirmation), is refused with 403, code `assurance_required`, and a `missing` list (`second_factor`, `recent_confirmation`) the client can act on. The host reports both through `Contracts\OperatorAssurance`. See [Operator assurance](operator-assurance.md).

---

[← Docs index](../../README.md#documentation)

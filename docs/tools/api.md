# REST API

An optional, off-by-default HTTP surface over the same services.

## Overview

The REST API is disabled by default (`api.enabled`) and mounts under `api.path` (default `api/db-console`). When enabled, every route sits behind the `laranail-db-console.api-guard` middleware: it enforces the API is enabled, the request is over HTTPS unless `api.require_https` is off (loopback is always allowed), the IP is allow-listed, and the caller is authenticated via the `api.auth` guard (`sanctum` or `passport`). The `laranail-db-console.api` rate limiter then applies `api.rate_limit` per caller. An IP that fails to authenticate `api.auth_failure_limit` times (default `10,1`: ten in a minute) is answered 429 with `Retry-After` until the window ends, before its credentials are looked at. **Authorization itself happens in the services** — the same Gate as the CLI and UI — so an out-of-scope caller gets a 403 identically. Destructive endpoints require a matching `confirm` field while `api.confirm_destructive` is on (the default).

## Existing resources

`POST …/accounts` and `POST …/databases` take an optional `on_existing` (`refuse`, `adopt`, `adopt_and_apply`). A resource that is already there and is refused answers 409 with `code` (`account.already_exists` or `database.already_exists`) and `options`, the values this caller may resubmit with. An adopted one answers 200, a new one 201. See [Adopt an existing account or database](../recipes/adopt-existing-resources.md).

## Retired keys

`api.guard` and `api.prefix` were honoured as fallbacks for `api.auth` and `api.path` through 0.1.1. They are no longer read, and `doctor` fails a config that still sets them (`config:legacy-api-keys`), naming the key to rename it to.

## Operator assurance

An operation the caller has the permission for, but not the assurance (a second factor, a recent confirmation), is refused with 403, code `assurance_required`, and a `missing` list (`second_factor`, `recent_confirmation`) the client can act on. The host reports both through `Contracts\OperatorAssurance`. See [Operator assurance](operator-assurance.md).

---

[← Docs index](../../README.md#documentation)

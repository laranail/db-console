# REST API

An optional, off-by-default HTTP surface over the same services.

## Overview

The REST API is disabled by default (`api.enabled`) and mounts under `api.path` (default `api/db-console`). When enabled, every route sits behind the `laranail-db-console.api-guard` middleware: it enforces the API is enabled, the request is over HTTPS unless `api.require_https` is off (loopback is always allowed), the IP is allow-listed, and the caller is authenticated via the `api.auth` guard (`sanctum` or `passport`). The `laranail-db-console.api` rate limiter then applies `api.rate_limit` per caller. **Authorization itself happens in the services** — the same Gate as the CLI and UI — so an out-of-scope caller gets a 403 identically. Destructive endpoints require a matching `confirm` field while `api.confirm_destructive` is on (the default).

## Existing resources

`POST …/accounts` and `POST …/databases` take an optional `on_existing` (`refuse`, `adopt`, `adopt_and_apply`). A resource that is already there and is refused answers 409 with `code` (`account.already_exists` or `database.already_exists`) and `options`, the values this caller may resubmit with. An adopted one answers 200, a new one 201. See [Adopt an existing account or database](../recipes/adopt-existing-resources.md).

## Deprecated keys

`api.guard` and `api.prefix` were what the code read until 0.1, though the config file defines `api.auth` and `api.path`. They are still honoured when the documented key is unset, with one deprecation notice each, and are removed no earlier than the next minor after 0.1. Exceptions render as secret-free JSON with a meaningful HTTP status. API tokens carry abilities that can never exceed the issuing operator's own permissions.

---

[← Docs index](../../README.md#documentation)

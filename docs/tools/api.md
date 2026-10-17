# REST API

An optional, off-by-default HTTP surface over the same services.

## Overview

The REST API is disabled by default (`api.enabled`). When enabled, every route sits behind the `laranail-db-console.api-guard` middleware: it enforces the API is enabled, the request is over HTTPS (outside local), the caller is authenticated via the configured guard (`sanctum` or `passport`), and the IP is allow-listed. **Authorization itself happens in the services** — the same Gate as the CLI and UI — so an out-of-scope caller gets a 403 identically. Destructive endpoints require a matching `confirm` field. Exceptions render as secret-free JSON with a meaningful HTTP status. API tokens carry abilities that can never exceed the issuing operator's own permissions.

## Operator assurance

An operation the caller has the permission for, but not the assurance (a second factor, a recent confirmation), is refused with 403, code `assurance_required`, and a `missing` list (`second_factor`, `recent_confirmation`) the client can act on. The host reports both through `Contracts\OperatorAssurance`. See [Operator assurance](operator-assurance.md).

---

[← Docs index](../../README.md#documentation)

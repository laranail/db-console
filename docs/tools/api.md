# REST API

An optional, off-by-default HTTP surface over the same services.

## Overview

The REST API is disabled by default (`api.enabled`). When enabled, every route sits behind the `laranail-db-console.api-guard` middleware: it enforces the API is enabled, the request is over HTTPS (outside local), the caller is authenticated via the configured guard (`sanctum` or `passport`), and the IP is allow-listed. **Authorization itself happens in the services** — the same Gate as the CLI and UI — so an out-of-scope caller gets a 403 identically. Destructive endpoints require a matching `confirm` field. Exceptions render as secret-free JSON with a meaningful HTTP status. API tokens carry abilities that can never exceed the issuing operator's own permissions.

## Two-factor and step-up

The guard also applies two-factor enforcement: an operator without it gets 403 with code `two_factor.required` (during the grace window, only for unsafe methods). Dangerous actions need step-up: send a current authenticator or recovery code as `X-DB-Console-OTP`, or get 403 with code `step_up.required`. See [Operator security](operator-security.md).

---

[← Docs index](../../README.md#documentation)

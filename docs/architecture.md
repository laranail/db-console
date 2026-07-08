# Architecture

The layers of `laranail/db-console`, the invariants they uphold, and why each is shaped this way.

## The layers

1. **Domain / value objects** — `DbName`, `Username`, `Host`, `Password`, `Charset`, `PrivilegeSet`. Allow-list validated on construction; secrets redact themselves. They depend on nothing.
2. **Shared validation** — Rules + FormRequests + `RuleProvider`. The single validation layer the CLI, API, and UI all consume, so an identifier that is rejected in one is rejected in all.
3. **Engines** — the ONLY layer that builds SQL. Per dialect, they turn value objects into exact statements via a `Quoter` and declare honest capabilities.
4. **Servers** — `ServerRegistry` + `AdminConnection`: the only thing that touches a managed server, over a dedicated admin connection, translating every driver error.
5. **Services** — `DatabaseManager`, `AccountManager`, `PrivilegeManager`, wizard, reconcile: authorize → resolve → check capability → ask the engine → run → record + audit.
6. **Access** — deny-by-default, scope-aware RBAC behind a driver seam.
7. **Audit / events / notifications / API / webhooks** — the observable surface.

## Invariants

- Only engines build SQL (enforced by an architecture test that must never be weakened).
- All input is allow-list validated by the shared layer before it reaches an engine.
- Privileges are capped below server-wide; `Privilege::forbidden()` is the single guard source.
- Secrets redact themselves everywhere — logs, exceptions, audit, webhooks.
- Every service method authorizes through the same Gate; RBAC is deny-by-default.
- The audit trail is append-only and hash-chained (tamper-evident).
- Reads are live from the server; the catalog is history.

## Why a dedicated admin connection, never root?

Admin credentials are the highest-value secret in the system. Scoping the account to exactly what DBConsole needs (and refusing root in `doctor`) limits the blast radius of a compromise and makes the tool auditable: what it *can* do is visible in one `SHOW GRANTS`.

## Why the catalog is not the source of truth

A server can be changed outside DBConsole. Treating the catalog as authoritative would let it drift into a comfortable lie. Instead every read is live, and `reconcile` reports drift rather than papering over it — DBConsole never silently mutates a server to match its records.

## Why the two-package split

All logic lives here, headless; the [`db-console-webui`](https://github.com/laranail/db-console-webui) wrapper is a thin Livewire/Flux surface that only calls these services and reuses this validation layer. The boundary is enforced by architecture tests in both packages, so the UI can never grow its own business logic or validation.

---

[← Docs index](../README.md#documentation)

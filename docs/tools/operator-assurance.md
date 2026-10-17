# Operator assurance

What an operation needs beyond its RBAC permission: a second factor, a recent confirmation, or both, as the host reports them.

## Overview

db-console authenticates nobody. The host owns login, two-factor, recovery codes, enrolment and passkeys, and answers two questions about the operator it signed in, through `Simtabi\Laranail\DBConsole\Contracts\OperatorAssurance`:

```php
interface OperatorAssurance
{
    public function hasSecondFactor(Authenticatable $operator): bool;

    public function recentlyConfirmedAt(Authenticatable $operator): ?DateTimeInterface;
}
```

db-console checks the answers against `security.assurance`, after the gate allows, in `Services\Access\Authorizer`: the single authorization entry point every service calls. That is what covers the REST API, the WebUI and CLI runs that carry an operator with one rule. A shell run with no operator is left to the host's gate, because shell access is already the trust boundary.

The default binding, `Security\NullOperatorAssurance`, reports no second factor and no confirmation, so under `require` every operation that needs assurance is refused until the host binds its own. See [Implement OperatorAssurance](../recipes/implement-operator-assurance.md). `Security\SessionConfirmationAssurance` is a starting point that reads Laravel's `auth.password_confirmed_at` session key and reports no second factor; it is not bound by default.

## What each operation requires

| Key | Default | Means |
|---|---|---|
| `security.assurance.second_factor` | `writes` | A second factor for every permission that is not a view (`ConsolePermission::isRead()`). `all` or `none` otherwise; an unrecognised value is `all`. |
| `security.assurance.recent_confirmation` | `database.drop`, `account.drop`, `account.rotate`, `account.edit`, `secrets.manage` | A confirmation no older than `security.step_up.ttl` (300 seconds). `account.edit` is the host change; `secrets.manage` covers `secrets:rotate`. |

`secrets:rotate` has no RBAC check of its own, so it calls `Authorizer::assure()`: an authenticated operator still needs the assurance `secrets.manage` requires.

## Enforcement

`security.assurance.enforce`. Unset, it is `require` in production and `report` elsewhere; an unrecognised value is `require`.

| Mode | An operator who falls short |
|---|---|
| `off` | is let through. `doctor` errors in production and warns elsewhere. |
| `report` | is let through. Every shortfall is audited as `AssuranceShortfall` (`would_refuse`), and one per operator per day is raised at warning severity, so the security recipients are alerted without being flooded. |
| `require` | is refused with `AssuranceRequired`, audited the same way (`refused`). |

`AssuranceRequired` carries what is missing (`missing()`), and its translated message tells the operator what to do: enrol in a second factor, confirm their password or passkey, or both. Over the API it renders as HTTP 403:

```json
{
    "message": "You have access to laranail-db-console.database.drop, but it needs more proof of who you are. Enrol in two-factor authentication. Confirm your password (or passkey), then retry.",
    "code": "assurance_required",
    "missing": ["second_factor", "recent_confirmation"]
}
```

A host maps `second_factor` to its enrolment page and `recent_confirmation` to `password.confirm`.

## Lockout prevention

- **Report first.** `report` shows, in the audit trail and the alerts, everything `require` would refuse.
- **Break-glass.** `laranail::db-console.security.recover <user>` runs only from the host's shell, never from an HTTP request. It lifts db-console's own assurance requirements for that operator for `security.break_glass_minutes` (15); it does not touch the host's login. A new bypass ends the previous one, and every issue raises a critical `BreakGlassIssued` alert. See [Recover a locked-out Owner](../recipes/recover-locked-out-owner.md).
- **Never the last Owner.** `role:revoke` refuses to take global Owner from the last operator holding it, and, while enforcement is on, from the last one with a second factor (`OwnerWouldBeStranded`, HTTP 409). A host that lets operators disable their second factor calls `Security\OwnerSafety::assertMayLoseSecondFactor($operator)` first, which refuses for the last global Owner with one.

## Storage

One catalog table, `security_bypasses`: the break-glass bypasses, who issued them and when they expire.

---

[← Docs index](../../README.md#documentation)

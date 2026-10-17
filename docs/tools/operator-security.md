# Operator security

Two-factor enforcement, step-up for dangerous actions, and the protections that keep them from locking anyone out.

## Overview

db-console stays headless: it never enrols anyone in two-factor and ships no WebAuthn code. It reads what the host's provider recorded (laranail/authkit, or Laravel Fortify) through the `Security\Contracts\TwoFactorStatus` contract, and asks for a fresh proof of identity through `Security\Contracts\StepUpVerifier`. Passkeys arrive through authkit-preset's `password.confirm`, which a passkey satisfies.

Both apply to an authenticated operator on every surface: the REST API, the WebUI, and CLI runs that carry one. A shell run with no operator is governed by the host's gate, because shell access is already the trust boundary: whoever has it can read `.env`.

## Two-factor enforcement

`security.two_factor.enforce` takes one of three modes. Unset, it is `require` in production and `report` everywhere else. An unrecognised value is treated as `require`, so a typo cannot switch enforcement off.

| Mode | An operator without two-factor |
|---|---|
| `off` | is let through. `doctor` warns, in every environment. |
| `report` | is let through. Each request enforcement would refuse is logged, and a `TwoFactorEnforced` alert (`would_refuse`) reaches the operator and the security recipients at most once a day. Nothing starts the grace clock. |
| `require` | is refused writes from the first request. Reads keep working for `grace_days` (7), counted from that first refused request; after that every request is refused. |

An operator counts as enrolled only with a confirmed second factor **and** recovery codes: `two_factor_confirmed_at` and `two_factor_recovery_codes` on the operator model, for the `fortify` driver. The `null` driver means no provider, so nobody is enrolled, and `doctor` reports that as an error under `require`. When an operator uses the last recovery code the column empties and they are refused until they regenerate codes in authkit.

The check runs in two places: in `Services\Access\Authorizer`, after the gate allows (a gate denial is still `NotAuthorized`), and in the API guard, which counts safe HTTP methods as reads. A refusal is `TwoFactorRequired`, HTTP 403, code `two_factor.required`. Read permissions are those for which `ConsolePermission::isRead()` is true: `access`, `dashboard.view`, `audit.view`, `server.view`, `database.view` and `account.view`.

The operator is told by mail (when the model is `Notifiable`) with the date reads stop, and the security recipients get a `SecurityAlertNotification`. Both at most once a day.

## Step-up

These actions need a fresh proof of identity on top of the permission, as `Enums\SensitiveAction`:

| Action | Where it is asked for |
|---|---|
| Drop a database | `DatabaseManager::drop()` |
| Drop an account | `AccountManager::drop()` |
| Rotate a password | `AccountManager::rotatePassword()` |
| Change an account's host | `AccountManager::changeHost()` |
| Rotate stored secrets | `SecretRotator::rotateAll()` |
| Apply a password to an adopted account | `SensitiveAction::AccountApplyPassword`, for the existing-resource policy |
| Regenerate recovery codes | `Security\RecoveryCodes::regenerate()` |

`security.step_up.driver` picks the verifier. An unrecognised value is treated as `session`, never `none`.

| Driver | Proof |
|---|---|
| `session` (default) | Laravel's `password.confirm` timestamp in the session, no older than `security.step_up.ttl` (300 seconds). authkit-preset sets it from a password or a passkey. A caller with no session (an API token, a CLI run) is asked for a one-time code instead. |
| `totp` | A current authenticator code or a recovery code, in the `X-DB-Console-OTP` header or the `--otp` option, verified by authkit's `TwoFactorAuthentication::verify()`. Without laranail/authkit installed no code verifies, so the action is refused; `doctor` says so. |
| `none` | Nothing. `doctor` reports it as an error in production. |

A proof that verifies is not checked again within the same request, because a recovery code is single-use. A refusal is `StepUpRequired`, HTTP 403, code `step_up.required`, and raises a `StepUpFailed` security alert that records whether a code was presented. An HTTP request with no operator is refused; a shell run with none is not asked.

## Lockout prevention

- **Grace window.** Above. Reads keep working while the operator enrols.
- **Recovery codes.** Required for enrolment to count. Regenerating them goes through step-up (`RecoveryCodes::regenerate()`; authkit's own endpoint already asks for a code).
- **Break-glass.** `laranail::db-console.security.recover <user>` runs only from the host's shell, never from an HTTP request. It issues a bypass for `security.two_factor.break_glass_minutes` (15): the operator counts as enrolled until it expires, and it stands in for step-up once. A new one ends the previous one. Every issue raises a critical `BreakGlassIssued` alert. See [Recover a locked-out Owner](../recipes/recover-locked-out-owner.md).
- **Never the last Owner.** `role:revoke` refuses to take global Owner from the last operator holding it, and, while enforcement is on, from the last one with two-factor. An enrolled global Owner disabling two-factor over HTTP, with no other enrolled global Owner, is refused with `OwnerWouldBeStranded` (HTTP 409): db-console watches the operator model's `two_factor_confirmed_at` going empty. The host's shell is exempt, so an administrator can still reset a lost device.
- **Dry run.** `report` shows what `require` would refuse before it is switched on.

## Storage

Two catalog tables: `two_factor_grace` (when each operator was first refused, and when they were last told) and `security_bypasses` (break-glass bypasses, with expiry and the step-up they spent).

---

[← Docs index](../../README.md#documentation)

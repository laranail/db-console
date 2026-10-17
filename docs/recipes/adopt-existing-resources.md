# Adopt an existing account or database

Take over an account or database that was on the server before db-console, deliberately and on the record.

## Why it is refused first

A create that finds its account or database already there changes nothing, answers `AccountAlreadyExists` or `DatabaseAlreadyExists` (HTTP 409), and notifies the security recipients. Something else may be using that login or that data, so taking it over is a separate decision, made by an operator holding `resource.adopt` (Owner only by default).

## The three policies

| Policy | What happens | Needs |
|---|---|---|
| `refuse` (default) | Nothing changes; the refusal is reported. | `account.create` / `database.create` |
| `adopt` | Recorded as managed, as it was found. An account keeps its password. | `resource.adopt` |
| `adopt_and_apply` | Adopted, then the account gets the supplied password (a generated one when none). A database is only adopted. | `resource.adopt`, `account.rotate`, step-up |

The policy is resolved most specific first: the call, then `servers.<name>.accounts.on_existing` (or `databases.on_existing`), then the global `accounts.on_existing` / `databases.on_existing`.

## CLI

```bash
php artisan laranail::db-console.user:create --server=primary --user=shop --host=% --on-existing=adopt
```

Without `--on-existing`, an interactive run asks, naming each option's risk. With `--no-interaction` the configured policy applies. A wizard re-run without `--password` adopts with `--on-existing=adopt` and never replaces the account's password.

## API

```http
POST /api/db-console/servers/primary/accounts
{"username": "shop", "host": "%", "generate": true}

409 {"code": "account.already_exists", "options": ["adopt", "adopt_and_apply"], "message": "…"}
```

`options` lists what this caller may resubmit with, and is empty when they may not adopt. Resubmit with `"on_existing": "adopt"`; an adopted resource answers 200 with `data.adopted: true`.

## PHP

```php
use Simtabi\Laranail\DBConsole\Enums\ExistingResourcePolicy;

$result = app(AccountManager::class)->create('primary', new Username('shop'), new Host('%'), null, ExistingResourcePolicy::Adopt);
$result->data['adopted'];           // true
$result->data['password_applied'];  // false
```

See [RBAC](../tools/rbac.md) for `resource.adopt` and [Events & notifications](../tools/events-notifications.md) for where the reports go.

---

[← Docs index](../../README.md#documentation)

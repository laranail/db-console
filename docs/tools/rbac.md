# RBAC

Deny-by-default, scope-aware role-based access control behind a driver seam.

## Overview

Every service method authorizes through the same Gate. Access is **deny-by-default**: an operator with no assignment covering the scope is denied. Scopes nest — `global` ⊇ `server:{name}` ⊇ `database:{server}/{db}` — so an assignment at a broader scope covers narrower ones.

## Drivers

`builtin` stores roles, permissions, and assignments in the catalog. `spatie` delegates role→permission composition to `spatie/laravel-permission` while DBConsole still owns the scope triple. Both drivers return identical verdicts for the same assignment.

## Gate abilities

Every console permission is a gate ability named `laranail-db-console.<permission>`, for example `laranail-db-console.database.view`. Check them from a host policy, a Blade `@can` or a route's `can:` middleware, with the scope as the second argument:

```php
Gate::allows('laranail-db-console.database.drop', 'server:prod-mysql');
```

```blade
@can('laranail-db-console.database.view')
    <a href="{{ route('laranail-db-console-webui.dashboard') }}">Databases</a>
@endcan
```

`ConsolePermission::DatabaseView->ability()` returns the same string, and is the spelling to prefer in PHP.

### Deprecated `db-console.*` abilities

The 21 abilities were named `db-console.<permission>` until 0.1. Each is still defined, as a deprecated alias that asks the gate for its scoped ability, so a host's own `Gate::define()` or before/after callback for `laranail-db-console.*` governs the old name too. The first check of each old name raises one `E_USER_DEPRECATED` notice naming its replacement. They are removed no earlier than the next minor after 0.1; rename them in host policies, `@can` directives and middleware.

Permission rows stored before the rename keep their `db-console.*` names. Both drivers read either form, so a custom role saved before 0.1 keeps its permissions, and `install` seeds the scoped names alongside them.

## Shipped roles

Owner, Admin, Operator, ReadOnly, Auditor are seeded on install; Owner composes to every permission. Assign with `role:assign --user --role --scope`; inspect with `access:show` and dry-run with `access:check`.

---

[← Docs index](../../README.md#documentation)

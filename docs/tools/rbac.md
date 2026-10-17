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

### The bare `db-console.*` abilities are gone

The abilities were named `db-console.<permission>` until 0.1, and kept as aliases of the scoped names through 0.1.1. They are no longer defined, so a host policy, `@can` directive or `can:` middleware still naming one is simply denied. Rename it to `laranail-db-console.<permission>`.

Permission rows stored under the old names are renamed by a catalog migration (`0001_01_01_001100`), for the builtin driver and for Spatie's tables. Where install had already seeded the scoped row beside an old one, the old row's roles move to it, so a custom role saved before 0.1 keeps every permission it had.

## Shipped roles

Owner, Admin, Operator, ReadOnly, Auditor are seeded on install; Owner composes to every permission. **`resource.adopt`**, taking over an account or database db-console did not create, is held by Owner alone: Admin has everything else except secrets and settings management. An install seeded before `resource.adopt` existed gains it on the shipped Owner when `php artisan migrate` runs the same migration; no other role changes. See [Adopt an existing account or database](../recipes/adopt-existing-resources.md). Assign with `role:assign --user --role --scope`; inspect with `access:show` and dry-run with `access:check`.

---

[← Docs index](../../README.md#documentation)

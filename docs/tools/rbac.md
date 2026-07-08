# RBAC

Deny-by-default, scope-aware role-based access control behind a driver seam.

## Overview

Every service method authorizes through the same Gate. Access is **deny-by-default**: an operator with no assignment covering the scope is denied. Scopes nest — `global` ⊇ `server:{name}` ⊇ `database:{server}/{db}` — so an assignment at a broader scope covers narrower ones.

## Drivers

`builtin` stores roles, permissions, and assignments in the catalog. `spatie` delegates role→permission composition to `spatie/laravel-permission` while DBConsole still owns the scope triple. Both drivers return identical verdicts for the same assignment.

## Shipped roles

Owner, Admin, Operator, ReadOnly, Auditor are seeded on install; Owner composes to every permission. Assign with `role:assign --user --role --scope`; inspect with `access:show` and dry-run with `access:check`.

---

[← Docs index](../../README.md#documentation)

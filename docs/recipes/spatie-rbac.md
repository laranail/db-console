# Use the Spatie RBAC driver

Back role→permission composition with spatie/laravel-permission.

## Steps

Install `spatie/laravel-permission`, publish its config and migrations, then set `rbac.driver` to `spatie`. DBConsole still owns the scope triple (global/server/database) and returns identical verdicts to the builtin driver; only role→permission composition is delegated to Spatie. Seed the shipped roles as usual. See [RBAC](../tools/rbac.md).

---

[← Docs index](../../README.md#documentation)

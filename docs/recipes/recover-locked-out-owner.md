# Recover a locked-out Owner

Let an Owner back into db-console for fifteen minutes while their second factor is restored.

## Steps

From the server's shell, not from a browser:

```bash
php artisan laranail::db-console.security.recover 42
```

db-console's assurance requirements are lifted for operator 42 until the printed expiry, and the security recipients are alerted. It does not touch your application's login: if the Owner cannot sign in, reset their second factor in your application (from the same shell, for example with `tinker`), then have them enrol again before the bypass expires. Running the command again ends the earlier bypass and starts a new one. See [Operator assurance](../tools/operator-assurance.md).

---

[← Docs index](../../README.md#documentation)

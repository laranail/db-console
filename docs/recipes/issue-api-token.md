# Issue an API token

Mint a Sanctum token whose abilities never exceed the operator's own.

## Steps

Enable the API (`api.enabled`, `api.guard`), then:

```bash
php artisan laranail::db-console.token:issue --user=42 --name=ci \\
  --abilities=database.view --abilities=database.create
```

The requested abilities must be a subset of what the operator holds at global scope — requesting more is rejected, not silently trimmed. The token is printed once. See [REST API](../tools/api.md).

---

[← Docs index](../../README.md#documentation)

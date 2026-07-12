# Configure a KMS / Vault / reference secret driver

Move off `app_key` for production secret storage.

## Steps

Set `secrets.driver` and the driver's block in `config/db-console.php`:

```php
'secrets' => [
    'driver' => 'kms',   // 'app_key' | 'kms' | 'vault' | 'reference'
    'kms' => ['provider' => 'aws', 'key_id' => env('DB_CONSOLE_KMS_KEY_ID')],
],
```

`app_key` is blocked in production without an explicit override, because it stores the key next to the ciphertext. Confirm the active driver with `php artisan laranail::db-console.secrets:driver`, and re-wrap existing secrets under new key material with `secrets:rotate`. See [Secret vault](../tools/secret-vault.md).

---

[← Docs index](../../README.md#documentation)

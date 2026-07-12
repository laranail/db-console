# Getting started

Register a server, provision a database and account, and read the audit trail.

## Register a server

Add a server to `config/db-console.php` (or register one at runtime with `server:add`):

```php
'servers' => [
    'primary' => [
        'engine' => 'mysql',
        'connection' => 'db_console_admin',   // a dedicated admin connection in database.php
        'tls' => ['enabled' => true, 'verify' => true],
    ],
],
```

Admin work always runs over this dedicated connection, never your app's default.

## Provision with the wizard

```bash
php artisan laranail::db-console.wizard \
  --server=primary --db=shop_prod --user=shop_app --host=% \
  --preset=app_standard --generate
```

The wizard creates the database, the account, and the grant as one flow with compensating rollback: if a later step fails, the earlier steps it created are undone (an empty database it made is dropped; a pre-existing one is never touched). The generated password is shown once and never stored.

## Read the audit trail

```bash
php artisan laranail::db-console.audit:view --server=primary
php artisan laranail::db-console.audit:verify   # checks the tamper-evident hash chain
```

## From code

```php
use Simtabi\Laranail\DBConsole\Services\{DatabaseManager, AccountManager, PrivilegeManager};
```

Every consumer — CLI, REST API, web UI, your own code — calls these same services, so authorization, validation, and auditing are identical everywhere.

---

[← Docs index](../README.md#documentation)

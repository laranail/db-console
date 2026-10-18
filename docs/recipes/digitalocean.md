# Connect to DigitalOcean Managed Databases

Point db-console at a DigitalOcean Managed MySQL or PostgreSQL cluster, over verified TLS, as a least-privilege admin.

## Steps

1. **Allow the application in.** Add the application's Droplet, Kubernetes cluster or IP to the cluster's *Trusted sources* in the DigitalOcean control panel.
2. **Download the CA certificate** from the cluster's *Connection details* and put it where the application can read it, for example `storage/certs/do-ca.crt`.
3. **Use the direct port, not the pool.** The cluster listens on `25060`. Port `25061` is a PostgreSQL connection pool, which grant transactions and drops do not survive; `doctor` warns about it.
4. **Choose the admin.** `doadmin` passes `doctor` on PostgreSQL (`CREATEDB CREATEROLE`, not a superuser). On MySQL, prefer the minimal admin from [Provision a minimal admin per engine](provision-minimal-admin.md), created while connected as `doadmin`.
5. **Configure the admin connection and the server:**

```dotenv
DB_CONSOLE_ADMIN_URL="postgresql://doadmin:…@db-postgresql-nyc3-12345-do-user-1-0.b.db.ondigitalocean.com:25060/defaultdb"
DB_CONSOLE_ENGINE=pgsql
DB_CONSOLE_PRIMARY_TLS=true
DB_CONSOLE_PRIMARY_TLS_CA=/var/www/app/storage/certs/do-ca.crt
DB_CONSOLE_PRIMARY_HOSTING=digitalocean
```

```php
// config/database.php
'db_console_admin' => [
    'driver' => 'pgsql',          // or 'mysql'
    'url'    => env('DB_CONSOLE_ADMIN_URL'),
],
```

6. **Check it:**

```bash
php artisan laranail::db-console.doctor
```

`server:primary:tls` should report `required_ok`, and `server:primary:hosting` should name DigitalOcean. On MySQL, `server:primary:server-settings` reports `sql_require_primary_key=ON`: every table your application creates there needs a primary key.

With `hosting=digitalocean`:

- `doadmin` and `_dodb` are left out of account listings;
- new MySQL accounts default to host `%`;
- dropping a database relies on the cluster's point-in-time recovery instead of a dump.

[Managed databases](../managed-databases.md) covers each of these.

---

[← Docs index](../../README.md#documentation)

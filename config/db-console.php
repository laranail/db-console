<?php

declare(strict_types=1);
use App\Models\User;

/*
 * laranail/db-console configuration.
 *
 * Registered through laranail/package-tools config namespacing, so every key
 * below is read as config('laranail.db-console.<key>').
 */
return [

    /*
    |--------------------------------------------------------------------------
    | Default server
    |--------------------------------------------------------------------------
    | The registered server used when a command, API call, or fluent call does
    | not name one explicitly.
    */
    'default' => env('DB_CONSOLE_SERVER', 'primary'),

    /*
    |--------------------------------------------------------------------------
    | Registered servers
    |--------------------------------------------------------------------------
    | Static, version-controlled server definitions. Each references a
    | dedicated admin connection in config/database.php (its own
    | DB_CONSOLE_* env vars) — never the app's default connection. Servers
    | can also be registered at runtime as DbServer catalog records; both
    | surface through the same ServerRegistry.
    */
    'servers' => [
        'primary' => [
            // String in config, resolved to the EngineType enum at boot;
            // an invalid value fails fast with the list of valid engines.
            'engine' => env('DB_CONSOLE_ENGINE', 'mysql'),

            // Dedicated admin connection name, NOT the app default.
            'connection' => env('DB_CONSOLE_CONNECTION', 'db_console_admin'),

            'tls' => [
                // Mandatory by default: admin auth crosses this connection.
                // doctor errors (not just warns) if a non-local server has
                // TLS off.
                'enabled' => (bool) env('DB_CONSOLE_PRIMARY_TLS', true),
                'verify' => true,
                'ca' => env('DB_CONSOLE_PRIMARY_TLS_CA'),
                // cert/key optional, for mutual TLS:
                'cert' => env('DB_CONSOLE_PRIMARY_TLS_CERT'),
                'key' => env('DB_CONSOLE_PRIMARY_TLS_KEY'),
            ],

            'at_rest' => [
                // Read-only: display whether databases on this server are
                // encrypted at rest. DBConsole never enables or manages it.
                'show_status' => true,
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Fluent facade alias
    |--------------------------------------------------------------------------
    */
    'facade_alias' => 'DbConsole',

    /*
    |--------------------------------------------------------------------------
    | Database defaults
    |--------------------------------------------------------------------------
    */
    'databases' => [
        'default_charset' => 'utf8mb4',
        'default_collation' => 'utf8mb4_unicode_ci',
    ],

    /*
    |--------------------------------------------------------------------------
    | Account defaults
    |--------------------------------------------------------------------------
    */
    'accounts' => [
        'default_host' => 'localhost',
        'password_min_length' => 16,
    ],

    /*
    |--------------------------------------------------------------------------
    | Catalog storage
    |--------------------------------------------------------------------------
    | DBConsole's own records (servers, databases, accounts, grants, audit)
    | live on a database connection you already have. Leave `connection` null
    | (the default) and DBConsole uses your app's default connection — zero
    | infrastructure, fully host-agnostic; the db_console_ prefix keeps its
    | tables out of the way. Set `connection` to a name for a DEDICATED,
    | isolated catalog: if that name is not defined in your database config,
    | DBConsole provisions a private SQLite file at `database` (outside the
    | web root) — the only mode where whole-file SQLCipher applies.
    */
    'catalog' => [
        'connection' => env('DB_CONSOLE_CATALOG_CONNECTION'),
        'prefix' => 'db_console_',
        'database' => env('DB_CONSOLE_CATALOG_PATH', storage_path('db-console/catalog.sqlite')),
    ],

    /*
    |--------------------------------------------------------------------------
    | Secrets (scope 1) — how admin credentials are stored
    |--------------------------------------------------------------------------
    | THE security-critical choice. SecretDriver enum: app_key | kms | vault
    | | reference. app_key is the default in local and is blocked in
    | production unless allow_app_key_in_production is explicitly true.
    */
    'secrets' => [
        'driver' => env('DB_CONSOLE_SECRET_DRIVER', 'app_key'),

        'allow_app_key_in_production' => (bool) env('DB_CONSOLE_ALLOW_APPKEY_IN_PROD', false),

        'kms' => [
            'provider' => env('DB_CONSOLE_KMS_PROVIDER', 'aws'), // KmsProvider enum: aws | gcp
            'key_id' => env('DB_CONSOLE_KMS_KEY_ID'),
            'region' => env('DB_CONSOLE_KMS_REGION'),
            // Uses the standard AWS/GCP SDK credential chain (IAM role preferred).
        ],

        'vault' => [
            'address' => env('DB_CONSOLE_VAULT_ADDR'),
            'auth' => env('DB_CONSOLE_VAULT_AUTH', 'approle'), // VaultAuthMethod enum: approle | token
            'role_id' => env('DB_CONSOLE_VAULT_ROLE_ID'),
            'secret_id' => env('DB_CONSOLE_VAULT_SECRET_ID'),
            'token' => env('DB_CONSOLE_VAULT_TOKEN'),
            'mount' => env('DB_CONSOLE_VAULT_MOUNT', 'secret'),
            'path_prefix' => env('DB_CONSOLE_VAULT_PATH', 'db-console'),
        ],

        'reference' => [
            // Where the real credentials live when nothing is stored locally:
            // aws-secrets-manager | vault | doppler
            'provider' => env('DB_CONSOLE_SECRET_REF_PROVIDER', 'aws-secrets-manager'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Catalog encryption (scope 2) — defense in depth, NOT credential protection
    |--------------------------------------------------------------------------
    */
    'catalog_encryption' => [
        // Hostnames/usernames encrypted via Laravel casts.
        'encrypt_topology_columns' => true,

        // Optional whole-file SQLCipher for the SQLite catalog. Requires the
        // pdo_sqlcipher extension; auto-disabled if absent. Protects a stolen
        // file/backup, NOT an on-box attacker.
        'sqlcipher' => [
            'enabled' => (bool) env('DB_CONSOLE_SQLCIPHER', false),
            'key' => env('DB_CONSOLE_CIPHER_KEY'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Console RBAC — who may use DBConsole, and where
    |--------------------------------------------------------------------------
    | RbacDriver enum: builtin | spatie. Deny-by-default either way: no role
    | assignment at a covering scope, no access.
    */
    'rbac' => [
        'driver' => env('DB_CONSOLE_RBAC', 'builtin'),

        // The app's user model, used as the assignee for role assignments.
        'user_model' => env('DB_CONSOLE_USER_MODEL', User::class),

        // Seed the shipped roles (Owner/Admin/Operator/ReadOnly/Auditor) on install.
        'seed_default_roles' => true,

        // The bootstrap operator assigned Owner @ global on install (scenario A).
        'owner_user_id' => env('DB_CONSOLE_OWNER_USER_ID'),

        // Teams: reserved for the future feature; false keeps the stub tables idle.
        'teams' => [
            'enabled' => false,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | REST API — off by default (the highest-exposure surface)
    |--------------------------------------------------------------------------
    */
    'api' => [
        'enabled' => (bool) env('DB_CONSOLE_API', false),
        'path' => env('DB_CONSOLE_API_PATH', 'api/db-console'),
        'auth' => env('DB_CONSOLE_API_AUTH', 'sanctum'), // ApiAuthGuard enum: sanctum | passport
        'allowed_ips' => array_filter(explode(',', (string) env('DB_CONSOLE_API_ALLOWED_IPS', ''))),
        'rate_limit' => env('DB_CONSOLE_API_RATE', '60,1'), // requests, per minutes
        'require_https' => true,
        // Destructive endpoints require a confirmation field in the body.
        'confirm_destructive' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Outbound webhooks
    |--------------------------------------------------------------------------
    */
    'webhooks' => [
        'enabled' => (bool) env('DB_CONSOLE_WEBHOOKS', false),
        'queue' => true,
        'timeout' => 5,
        'max_attempts' => 5, // retries with backoff, then auto-disable + alert
        'sign_with' => 'sha256', // HMAC over the payload using each subscription's secret
    ],

    /*
    |--------------------------------------------------------------------------
    | Logging
    |--------------------------------------------------------------------------
    | A dedicated channel so DBConsole activity is separable from app logs.
    */
    'logging' => [
        'channel' => env('DB_CONSOLE_LOG_CHANNEL', 'db-console'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Backup before destructive operations
    |--------------------------------------------------------------------------
    | Requires laranail/db-tools; disabled with a clear notice when absent.
    */
    'backup' => [
        'enabled' => true,
        'before_drop' => true,
        'disk' => env('DB_CONSOLE_BACKUP_DISK', 'local'),
        'retention_days' => 30,
    ],

    /*
    |--------------------------------------------------------------------------
    | Notifications
    |--------------------------------------------------------------------------
    | Recipients per NotificationCategory. Default: off for routine events,
    | on for destructive and security events.
    */
    'notifications' => [
        'recipients' => [
            'routine' => [],
            'destructive' => array_filter(explode(',', (string) env('DB_CONSOLE_ALERT_MAIL', ''))),
            'security' => array_filter(explode(',', (string) env('DB_CONSOLE_ALERT_MAIL', ''))),
        ],
        'quiet_hours' => null,
        'queue' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Alerts — the high-severity channel
    |--------------------------------------------------------------------------
    */
    'alerts' => [
        'channel' => env('DB_CONSOLE_ALERT_CHANNEL', 'mail'),
        'webhook' => env('DB_CONSOLE_ALERT_WEBHOOK'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Audit trail
    |--------------------------------------------------------------------------
    */
    'audit' => [
        'enabled' => true,
        'hash_chain' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Custom privilege presets
    |--------------------------------------------------------------------------
    | Named custom presets, validated against the active engine's allow-list
    | at boot.
    */
    'presets' => [],
];

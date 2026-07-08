<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DBConsole\Providers;

use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Events\ConnectionEstablished;
use Override;
use Psr\Log\LoggerInterface;
use Simtabi\Laranail\DBConsole\Access\Contracts\AccessManager;
use Simtabi\Laranail\DBConsole\Access\Contracts\RbacDriver;
use Simtabi\Laranail\DBConsole\Access\Drivers\BuiltinRbacDriver;
use Simtabi\Laranail\DBConsole\Access\Drivers\SpatieRbacDriver;
use Simtabi\Laranail\DBConsole\Access\RbacAccessManager;
use Simtabi\Laranail\DBConsole\Audit\AuditLogObserver;
use Simtabi\Laranail\DBConsole\Authorization\DBConsolePolicy;
use Simtabi\Laranail\DBConsole\Backup\BackupService;
use Simtabi\Laranail\DBConsole\Backup\DbToolsBackupService;
use Simtabi\Laranail\DBConsole\Catalog\CatalogConnection;
use Simtabi\Laranail\DBConsole\Encryption\AtRestStatusReader;
use Simtabi\Laranail\DBConsole\Encryption\SqlCipherManager;
use Simtabi\Laranail\DBConsole\Encryption\TlsChecker;
use Simtabi\Laranail\DBConsole\Enums\RbacDriver as RbacDriverEnum;
use Simtabi\Laranail\DBConsole\Events\Contracts\RecordsToAudit;
use Simtabi\Laranail\DBConsole\Http\Middleware\ApiGuard;
use Simtabi\Laranail\DBConsole\Listeners\RaiseAlerts;
use Simtabi\Laranail\DBConsole\Listeners\SendNotifications;
use Simtabi\Laranail\DBConsole\Listeners\WriteAuditLog;
use Simtabi\Laranail\DBConsole\Listeners\WriteChannelLog;
use Simtabi\Laranail\DBConsole\Logging\ContextScrubber;
use Simtabi\Laranail\DBConsole\Logging\DBConsoleLogger;
use Simtabi\Laranail\DBConsole\Models\AuditLog;
use Simtabi\Laranail\DBConsole\Secrets\Contracts\SecretStore;
use Simtabi\Laranail\DBConsole\Secrets\SecretRotator;
use Simtabi\Laranail\DBConsole\Secrets\SecretVault;
use Simtabi\Laranail\DBConsole\Secrets\SecretVaultManager;
use Simtabi\Laranail\DBConsole\Secrets\Stores\DatabaseSecretStore;
use Simtabi\Laranail\DBConsole\Servers\ServerRegistry;
use Simtabi\Laranail\DBConsole\Services\AccountManager;
use Simtabi\Laranail\DBConsole\Services\Catalog\DbConsoleCatalog;
use Simtabi\Laranail\DBConsole\Services\Contracts\Catalog;
use Simtabi\Laranail\DBConsole\Services\DatabaseManager;
use Simtabi\Laranail\DBConsole\Services\PrivilegeManager;
use Simtabi\Laranail\DBConsole\Services\ProvisioningWizard;
use Simtabi\Laranail\DBConsole\Services\ReconcileService;
use Simtabi\Laranail\DBConsole\Services\WizardExecutor;
use Simtabi\Laranail\DBConsole\Webhooks\DeliverWebhooks;
use Simtabi\Laranail\DBConsole\Webhooks\WebhookManager;
use Simtabi\Laranail\Package\Tools\Commands\InstallCommand;
use Simtabi\Laranail\Package\Tools\Package;
use Simtabi\Laranail\Package\Tools\Providers\PackageServiceProvider;
use Simtabi\Laranail\Package\Tools\Support\Definitions\InstallCommandDefinition;

final class DBConsoleServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        $package
            ->name('laranail/db-console')
            ->hasConfigFile()
            ->hasTranslations('db-console')
            ->discoversMigrations()
            ->runsMigrations()
            ->hasRoutesWhen('laranail.db-console.api.enabled', 'api')
            ->registerMiddlewareAliases(['db-console.api-guard' => ApiGuard::class])
            ->hasCommands($this->commandClasses())
            ->hasInstallCommand($this->installDefinition());
    }

    /**
     * The db-console:install flow (scenario A): publish config + lang, run
     * migrations, seed the shipped console roles, assign Owner @ global to the
     * bootstrap operator (DB_CONSOLE_OWNER_USER_ID when set), then run doctor.
     */
    private function installDefinition(): InstallCommandDefinition
    {
        return InstallCommandDefinition::make()
            ->named('db-console:install')
            ->publishes('config', 'translations')
            ->runsMigrations()
            ->step('Seed console roles', function (InstallCommand $command): void {
                $command->getLaravel()->make(RbacDriver::class)->seedDefaultRoles();
                $command->info('Seeded the shipped console roles.');
            })
            ->step('Assign the bootstrap Owner', function (InstallCommand $command): void {
                $this->assignBootstrapOwner($command);
            })
            ->step('Run doctor', function (InstallCommand $command): void {
                $command->call('laranail::db-console.doctor');
            });
    }

    private function assignBootstrapOwner(InstallCommand $command): void
    {
        $app = $command->getLaravel();
        $config = $app->make(Config::class);
        $ownerId = $config->get('laranail.db-console.rbac.owner_user_id');
        if ($ownerId === null || $ownerId === '') {
            $command->warn('No bootstrap Owner set. Assign one with: php artisan laranail::db-console.role:assign --user=<id> --role=owner --scope=global');

            return;
        }

        /** @var class-string<Model> $userModel */
        $userModel = (string) $config->get('laranail.db-console.rbac.user_model', '\App\Models\User');
        if (! class_exists($userModel)) {
            return;
        }

        $owner = $userModel::query()->find($ownerId);
        if ($owner instanceof Authenticatable) {
            $app->make(RbacDriver::class)->assignBootstrapOwner($owner);
            $command->info("Assigned Owner @ global to user {$ownerId}.");
        }
    }

    /**
     * @return list<class-string>
     */
    private function commandClasses(): array
    {
        $namespace = 'Simtabi\\Laranail\\DBConsole\\Console\\Commands\\';

        return array_map(
            static fn (string $class): string => $namespace . $class,
            [
                'DoctorCommand',
                'DbCreateCommand', 'DbListCommand', 'DbDropCommand',
                'UserCreateCommand', 'UserListCommand', 'UserPasswordCommand', 'UserDropCommand', 'UserEditCommand',
                'GrantCommand', 'RevokeCommand', 'AttachCommand', 'DetachCommand',
                'WizardCommand', 'ReconcileCommand',
                'ServerAddCommand', 'ServerListCommand', 'ServerUseCommand',
                'AuditViewCommand', 'AuditVerifyCommand',
                'SecretsRotateCommand', 'SecretsDriverCommand', 'EncryptionStatusCommand',
                'RoleListCommand', 'RoleCreateCommand', 'RoleAssignCommand', 'RoleRevokeCommand',
                'AccessShowCommand', 'AccessCheckCommand',
                'TokenIssueCommand', 'WebhookListCommand', 'WebhookAddCommand', 'WebhookRemoveCommand',
            ],
        );
    }

    #[Override]
    public function packageRegistered(): void
    {
        $this->registerCatalogConnection();
        $this->registerSecrets();
        $this->registerServices();
        $this->registerAccess();
    }

    #[Override]
    public function packageBooted(): void
    {
        $this->applyCatalogPragmas();
        $this->guardSecretDriver();
        $this->registerAuditPipeline();
        $this->registerGate();
    }

    /**
     * Bind the RBAC driver (builtin | spatie) and the scope-aware
     * AccessManager behind it. Resolution logic is identical for both drivers
     * (RbacAccessManager + ScopeResolver); only storage differs. Deny-by-
     * default holds: an operator with no assignment covering the scope is
     * denied (section 17).
     */
    private function registerAccess(): void
    {
        $this->app->singleton(RbacDriver::class, function ($app): RbacDriver {
            $driver = RbacDriverEnum::tryFrom(
                (string) $app->make(Config::class)->get('laranail.db-console.rbac.driver', 'builtin'),
            ) ?? RbacDriverEnum::Builtin;

            return match ($driver) {
                RbacDriverEnum::Spatie => $app->make(SpatieRbacDriver::class),
                RbacDriverEnum::Builtin => $app->make(BuiltinRbacDriver::class),
            };
        });

        $this->app->bindIf(AccessManager::class, RbacAccessManager::class);
    }

    /**
     * Every domain event → the append-only, hash-chained audit trail and the
     * dedicated log channel. Listeners bind to the RecordsToAudit interface,
     * so one registration each covers every event. The AuditLog observer
     * makes the trail append-only by construction.
     */
    private function registerAuditPipeline(): void
    {
        $events = $this->app->make(Dispatcher::class);
        $events->listen(RecordsToAudit::class, WriteAuditLog::class);
        $events->listen(RecordsToAudit::class, WriteChannelLog::class);
        $events->listen(RecordsToAudit::class, SendNotifications::class);
        $events->listen(RecordsToAudit::class, RaiseAlerts::class);
        $events->listen(RecordsToAudit::class, DeliverWebhooks::class);

        AuditLog::observe(AuditLogObserver::class);
    }

    /**
     * Register the DBConsole gate abilities (one per ConsolePermission),
     * resolved through the AccessManager.
     */
    private function registerGate(): void
    {
        $this->app->make(DBConsolePolicy::class)->register($this->app->make(Gate::class));
    }

    /**
     * Register the dedicated catalog connection (default db_console_catalog)
     * unless the host app already defined one, so the package works with no
     * database.php edits. Also binds the SQLCipher detector.
     */
    private function registerCatalogConnection(): void
    {
        // Resolve the catalog connection ONCE and write it back to config, so
        // every downstream reader (SqlCipherManager, CatalogConnection,
        // DatabaseSecretStore, the migrations, and the models) sees the same
        // concrete name. When unset, DBConsole rides the host app's default
        // connection — no dedicated infrastructure. A configured name that the
        // host has not defined falls through to the dedicated-SQLite synthesis
        // below (isolation / whole-file SQLCipher).
        $rootConfig = $this->app->make(Config::class);
        $resolvedCatalog = $this->stringOrNull($rootConfig->get('laranail.db-console.catalog.connection'))
            ?? (string) $rootConfig->get('database.default');
        $rootConfig->set('laranail.db-console.catalog.connection', $resolvedCatalog);

        $this->app->singleton(SqlCipherManager::class, function ($app): SqlCipherManager {
            $config = $app->make(Config::class);
            $catalogName = (string) $config->get('laranail.db-console.catalog.connection', 'db_console_catalog');
            $catalogDriver = $config->get("database.connections.{$catalogName}.driver", 'sqlite');

            return new SqlCipherManager(
                sqlcipherEnabled: (bool) $config->get('laranail.db-console.catalog_encryption.sqlcipher.enabled', false),
                cipherKey: $this->stringOrNull($config->get('laranail.db-console.catalog_encryption.sqlcipher.key')),
                catalogIsSqlite: $catalogDriver === 'sqlite',
            );
        });

        $this->app->singleton(CatalogConnection::class, fn ($app): CatalogConnection => new CatalogConnection(
            $app->make(Config::class),
            $app->make(SqlCipherManager::class),
        ));

        $config = $this->app->make(Config::class);
        $catalog = $this->app->make(CatalogConnection::class);
        $name = $catalog->name();

        if ($config->get("database.connections.{$name}") === null) {
            $config->set("database.connections.{$name}", $catalog->definition());
        }
    }

    private function registerSecrets(): void
    {
        $this->app->singleton(SecretStore::class, function ($app): SecretStore {
            $config = $app->make(Config::class);

            return new DatabaseSecretStore(
                $app->make(ConnectionResolverInterface::class),
                (string) $config->get('laranail.db-console.catalog.connection', 'db_console_catalog'),
                ($config->get('laranail.db-console.catalog.prefix', 'db_console_')) . 'secrets',
            );
        });

        $this->app->singleton(SecretVaultManager::class, fn ($app): SecretVaultManager => new SecretVaultManager(
            $app,
            $app->make(Config::class),
            $app->make(SecretStore::class),
        ));

        $this->app->singleton(SecretVault::class, fn ($app): SecretVault => $app->make(SecretVaultManager::class)->make());
    }

    /**
     * Bind the multi-server registry, the logger, and the three service
     * managers. The catalog defaults to NullCatalog until the Eloquent-backed
     * catalog is bound (A5), so the services work headless before persistence.
     */
    private function registerServices(): void
    {
        $this->app->singleton(ServerRegistry::class);

        $this->app->singleton(DBConsoleLogger::class, fn ($app): DBConsoleLogger => new DBConsoleLogger(
            $app->make(LoggerInterface::class),
            $app->make(Config::class),
            $app->make(ContextScrubber::class),
        ));

        // The Eloquent-backed catalog is the default; NullCatalog remains
        // available for explicit headless use where no persistence is wanted.
        $this->app->bindIf(Catalog::class, DbConsoleCatalog::class);

        $this->app->bindIf(BackupService::class, DbToolsBackupService::class);

        $this->app->singleton(DatabaseManager::class);
        $this->app->singleton(AccountManager::class);
        $this->app->singleton(PrivilegeManager::class);
        $this->app->singleton(WizardExecutor::class);
        $this->app->singleton(ProvisioningWizard::class);
        $this->app->singleton(ReconcileService::class);
        $this->app->singleton(TlsChecker::class);
        $this->app->singleton(AtRestStatusReader::class);
        $this->app->singleton(SecretRotator::class);
        $this->app->singleton(WebhookManager::class);
    }

    /**
     * Apply the SQLCipher PRAGMA key on every open of the catalog connection
     * when whole-file encryption is active. No-op otherwise (the common
     * case, including hosts without pdo_sqlcipher), so the listener is
     * always safe to register.
     */
    private function applyCatalogPragmas(): void
    {
        $catalog = $this->app->make(CatalogConnection::class);
        $pragmas = $catalog->bootstrapPragmas();
        if ($pragmas === []) {
            return;
        }

        $name = $catalog->name();

        $this->app->make(Dispatcher::class)->listen(
            ConnectionEstablished::class,
            static function (ConnectionEstablished $event) use ($name, $pragmas): void {
                if ($event->connectionName !== $name) {
                    return;
                }

                foreach ($pragmas as $pragma) {
                    $event->connection->statement($pragma);
                }
            },
        );
    }

    /**
     * Fail closed at boot if app_key is selected in production without the
     * explicit override — a misconfigured production deploy is stopped
     * before it serves a request (section 8, section 10). The guard itself
     * no-ops outside production.
     */
    private function guardSecretDriver(): void
    {
        $this->app->make(SecretVaultManager::class)->assertSecureForEnvironment();
    }

    private function stringOrNull(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}

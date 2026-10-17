<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Event;
use Simtabi\Laranail\DBConsole\Domain\DbName;
use Simtabi\Laranail\DBConsole\Enums\OperationType;
use Simtabi\Laranail\DBConsole\Events\DatabaseDropped;
use Simtabi\Laranail\DBConsole\Exceptions\BackupFailed;
use Simtabi\Laranail\DBConsole\Services\DatabaseManager;
use Simtabi\Laranail\DBConsole\Backup\DbToolsBackupService;
use Simtabi\Laranail\DbTools\Backup\Contracts\BackupManagerInterface;
use Simtabi\Laranail\DBConsole\Events\OperationFailed as OperationFailedEvent;

/**
 * Records what db-tools was asked to dump, and fails on request.
 */
final class RecordingBackupManager implements BackupManagerInterface
{
    /** @var list<array{path: string, connection: ?string, config: array<string, mixed>}> */
    public array $calls = [];

    public function __construct(private readonly bool $fails = false) {}

    public function backup(string $path, ?string $connection = null): bool
    {
        $this->calls[] = ['path' => $path, 'connection' => $connection, 'config' => (array) config("database.connections.{$connection}")];

        if ($this->fails) {
            throw new RuntimeException('mysqldump: Got error: 2026: TLS/SSL error');
        }

        return true;
    }

    public function restore(string $path, ?string $connection = null): bool
    {
        return true;
    }

    public function supportsDriver(string $driver): bool
    {
        return true;
    }
}

beforeEach(function (): void {
    Gate::before(fn ($user = null): bool => true);
    $this->migrateCatalog();

    config()->set('filesystems.disks.local', ['driver' => 'local', 'root' => sys_get_temp_dir() . '/db-console-backup-test']);
    // SQLite reports every database as non-empty, so the drop always snapshots.
    config()->set('database.connections.backup_admin', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
    config()->set('laranail.db-console.servers.lite', [
        'engine' => 'sqlite', 'connection' => 'backup_admin', 'tls' => ['enabled' => false],
    ]);
});

function useBackupManager(bool $fails): RecordingBackupManager
{
    $manager = new RecordingBackupManager($fails);
    app()->instance(BackupManagerInterface::class, $manager);

    return $manager;
}

it('does not drop a database whose backup failed, and audits the failed drop', function (): void {
    Event::fake([OperationFailedEvent::class, DatabaseDropped::class]);
    useBackupManager(fails: true);

    expect(fn () => app(DatabaseManager::class)->drop('lite', new DbName('shop_prod')))
        ->toThrow(BackupFailed::class);

    Event::assertNotDispatched(DatabaseDropped::class);
    Event::assertDispatched(
        OperationFailedEvent::class,
        fn (OperationFailedEvent $event): bool => $event->operation() === OperationType::DatabaseDrop,
    );
});

it('drops anyway when abort_drop_on_failure is switched off', function (): void {
    Event::fake([DatabaseDropped::class]);
    config()->set('laranail.db-console.backup.abort_drop_on_failure', false);
    useBackupManager(fails: true);

    app(DatabaseManager::class)->drop('lite', new DbName('shop_prod'));

    Event::assertDispatched(DatabaseDropped::class);
});

it('gives each snapshot its own timestamped file, named for the dump format', function (): void {
    $manager = useBackupManager(fails: false);
    config()->set('database.connections.pg_admin', ['driver' => 'pgsql', 'host' => 'db.example.com', 'database' => 'defaultdb']);
    config()->set('laranail.db-console.servers.pg', [
        'engine' => 'pgsql', 'connection' => 'pg_admin', 'tls' => ['enabled' => false],
    ]);

    $service = app(DbToolsBackupService::class);
    $first = $service->snapshot('pg', new DbName('shop_prod'));
    $this->travel(1)->seconds();
    $second = $service->snapshot('pg', new DbName('shop_prod'));

    expect($first)->toMatch('#^db-console/backups/pg/shop_prod-\d{8}T\d{6}Z\.dump$#')
        ->and($second)->not->toBe($first)
        ->and($manager->calls)->toHaveCount(2);
});

it('dumps the target database when the admin connection is a url', function (): void {
    $manager = useBackupManager(fails: false);
    config()->set('database.connections.url_admin', [
        'driver' => 'mysql', 'url' => 'mysql://doadmin:pw@db-mysql.b.db.ondigitalocean.com:25060/defaultdb',
    ]);
    config()->set('laranail.db-console.servers.managed', [
        'engine' => 'mysql', 'connection' => 'url_admin', 'tls' => ['enabled' => false],
    ]);

    app(DbToolsBackupService::class)->snapshot('managed', new DbName('shop_prod'));

    $config = $manager->calls[0]['config'];

    expect($config['database'])->toBe('shop_prod')
        ->and($config)->not->toHaveKey('url')
        ->and($config['host'])->toBe('db-mysql.b.db.ondigitalocean.com')
        ->and((string) $config['port'])->toBe('25060')
        ->and(config("database.connections.{$manager->calls[0]['connection']}"))->toBeNull();
});

it('refuses a non-local backup disk instead of dumping into the working directory', function (): void {
    useBackupManager(fails: false);
    config()->set('filesystems.disks.s3_backups', ['driver' => 's3', 'bucket' => 'b', 'region' => 'us-east-1', 'key' => 'k', 'secret' => 's']);
    config()->set('laranail.db-console.backup.disk', 's3_backups');

    expect(fn () => app(DbToolsBackupService::class)->snapshot('lite', new DbName('shop_prod')))
        ->toThrow(BackupFailed::class);
});

it('prunes only this database\'s own snapshots older than retention_days', function (): void {
    useBackupManager(fails: false);
    config()->set('laranail.db-console.backup.retention_days', 30);

    $dir = sys_get_temp_dir() . '/db-console-backup-test/db-console/backups/lite';
    @mkdir($dir, 0700, true);
    $old = "{$dir}/shop_prod-20250101T000000Z.sql";
    $recent = "{$dir}/shop_prod-" . now()->subDays(2)->utc()->format('Ymd\THis\Z') . '.sql';
    $otherDb = "{$dir}/shop_staging-20250101T000000Z.sql";
    $foreign = "{$dir}/shop_prod-notes.sql";
    foreach ([$old, $recent, $otherDb, $foreign] as $file) {
        file_put_contents($file, '-- x');
        touch($file, $file === $recent ? now()->subDays(2)->getTimestamp() : now()->subDays(400)->getTimestamp());
    }

    app(DbToolsBackupService::class)->snapshot('lite', new DbName('shop_prod'));

    expect(file_exists($old))->toBeFalse()
        ->and(file_exists($recent))->toBeTrue()
        ->and(file_exists($otherDb))->toBeTrue('another database\'s snapshot is never touched')
        ->and(file_exists($foreign))->toBeTrue('a file this service did not name is never touched');

    array_map(@unlink(...), glob("{$dir}/*") ?: []);
});

it('leaves a managed server to the provider\'s point-in-time recovery and still drops', function (): void {
    Event::fake([DatabaseDropped::class]);
    $manager = useBackupManager(fails: false);
    config()->set('laranail.db-console.servers.lite.hosting', 'generic');

    expect(app(DbToolsBackupService::class)->snapshot('lite', new DbName('shop_prod')))->toBeNull()
        ->and($manager->calls)->toBe([]);

    app(DatabaseManager::class)->drop('lite', new DbName('shop_prod'));

    expect($manager->calls)->toBe([]);
    Event::assertDispatched(DatabaseDropped::class);
});

it('still dumps a managed server when dump_on_managed is switched on', function (): void {
    $manager = useBackupManager(fails: false);
    config()->set('laranail.db-console.servers.lite.hosting', 'generic');
    config()->set('laranail.db-console.backup.dump_on_managed', true);

    expect(app(DbToolsBackupService::class)->snapshot('lite', new DbName('shop_prod')))->not->toBeNull()
        ->and($manager->calls)->toHaveCount(1);
});

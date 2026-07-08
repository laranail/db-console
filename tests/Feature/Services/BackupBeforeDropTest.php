<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Simtabi\Laranail\DBConsole\Backup\BackupService;
use Simtabi\Laranail\DBConsole\Backup\DbToolsBackupService;
use Simtabi\Laranail\DBConsole\Domain\Charset;
use Simtabi\Laranail\DBConsole\Domain\DbName;
use Simtabi\Laranail\DBConsole\Events\DatabaseBackedUp;
use Simtabi\Laranail\DBConsole\Services\DatabaseManager;
use Simtabi\Laranail\DBConsole\Tests\Concerns\InteractsWithDockerServers;

uses(InteractsWithDockerServers::class);

/**
 * A recording fake so the test does not depend on mysqldump being on the host;
 * it proves the drop flow CALLS the backup for a non-empty database before
 * dropping, and skips it for an empty one.
 */
function recordingBackup(): BackupService
{
    return new class implements BackupService
    {
        /** @var list<string> */
        public array $snapshotted = [];

        public function snapshot(string $server, DbName $database): string
        {
            $this->snapshotted[] = $database->value;

            return "db-console/backups/{$server}/{$database->value}.sql";
        }

        public function available(): bool
        {
            return true;
        }

        public function unavailableReason(): ?string
        {
            return null;
        }
    };
}

beforeEach(function (): void {
    $this->registerMysqlServer();
    $this->migrateCatalog();
    Gate::before(fn ($user = null): bool => true);

    $this->backup = recordingBackup();
    $this->app->instance(BackupService::class, $this->backup);
    // Rebind DatabaseManager so it receives the fake backup service.
    $this->app->forgetInstance(DatabaseManager::class);

    $this->suffix = $this->uniqueSuffix();
    $this->db = "dbc_bak_{$this->suffix}";
});

afterEach(function (): void {
    try {
        DB::connection('db_console_admin')->statement("DROP DATABASE IF EXISTS `{$this->db}`");
    } catch (Throwable) {
    }
});

it('snapshots a non-empty database before dropping it and fires DatabaseBackedUp', function (): void {
    Event::fake([DatabaseBackedUp::class]);

    $databases = app(DatabaseManager::class);
    $databases->create('docker-mysql', new DbName($this->db), new Charset('utf8mb4'));
    DB::connection('db_console_admin')->statement("CREATE TABLE `{$this->db}`.t (id INT)");

    $databases->drop('docker-mysql', new DbName($this->db));

    expect($this->backup->snapshotted)->toContain($this->db)
        ->and($databases->exists('docker-mysql', new DbName($this->db)))->toBeFalse();

    Event::assertDispatched(DatabaseBackedUp::class);
});

it('does not snapshot an empty database (nothing to protect)', function (): void {
    $databases = app(DatabaseManager::class);
    $databases->create('docker-mysql', new DbName($this->db), new Charset('utf8mb4'));

    $databases->drop('docker-mysql', new DbName($this->db));

    expect($this->backup->snapshotted)->not->toContain($this->db);
});

it('reports unavailable with a clear reason when backups are disabled', function (): void {
    config()->set('laranail.db-console.backup.enabled', false);

    // The real DbToolsBackupService reflects config.
    $service = app(DbToolsBackupService::class);

    expect($service->available())->toBeFalse()
        ->and($service->unavailableReason())->toContain('disabled');
});

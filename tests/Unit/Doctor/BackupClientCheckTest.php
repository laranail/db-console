<?php

declare(strict_types=1);

use Simtabi\Laranail\DBConsole\Domain\DbName;
use Simtabi\Laranail\DBConsole\Enums\Severity;
use Simtabi\Laranail\DBConsole\Backup\BackupService;
use Simtabi\Laranail\DBConsole\Doctor\DoctorFinding;
use Simtabi\Laranail\DBConsole\Doctor\DoctorService;
use Simtabi\Laranail\DBConsole\Backup\PreflightsBackups;
use Simtabi\Laranail\DBConsole\Doctor\BackupClientCheck;
use Simtabi\Laranail\DBConsole\Backup\BackupClientStatus;

/**
 * A backup service whose preflight answers as told, or throws.
 */
function preflightingBackup(?BackupClientStatus $status, ?Throwable $throws = null): BackupService
{
    return new readonly class($status, $throws) implements BackupService, PreflightsBackups
    {
        public function __construct(private ?BackupClientStatus $status, private ?Throwable $throws) {}

        public function preflight(string $server): ?BackupClientStatus
        {
            if ($this->throws instanceof Throwable) {
                throw $this->throws;
            }

            return $this->status;
        }

        public function snapshot(string $server, DbName $database): ?string
        {
            return null;
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

/**
 * @return list<DoctorFinding>
 */
function backupClientFindings(BackupService $backups): array
{
    app()->instance(BackupService::class, $backups);

    return app(BackupClientCheck::class)->findings('main');
}

function unusableClient(): BackupClientStatus
{
    return new BackupClientStatus(
        ready: false,
        tool: 'pg_dump',
        binary: 'pg_dump',
        message: 'pg_dump 16 is older than the PostgreSQL 18 server at db.internal, and cannot dump it.',
    );
}

it('says nothing for a backup service that cannot preflight', function (): void {
    $plain = new class implements BackupService
    {
        public function snapshot(string $server, DbName $database): ?string
        {
            return null;
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

    expect(backupClientFindings($plain))->toBe([]);
});

it('says nothing when there is nothing to check', function (): void {
    expect(backupClientFindings(preflightingBackup(null)))->toBe([]);
});

it('reports a usable client', function (): void {
    $findings = backupClientFindings(preflightingBackup(new BackupClientStatus(true, 'pg_dump', 'pg_dump', 'pg_dump 18 can dump the PostgreSQL 18 server at db.internal.')));

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->check)->toBe('server:main:backup-client')
        ->and($findings[0]->severity)->toBe(Severity::Info)
        ->and($findings[0]->message)->toContain('pg_dump 18 can dump');
});

it('fails doctor when a drop would be refused for want of a usable client', function (): void {
    config()->set('laranail.db-console.backup.abort_drop_on_failure', true);

    $finding = backupClientFindings(preflightingBackup(unusableClient()))[0];

    expect($finding->severity)->toBe(Severity::Error)
        ->and($finding->message)->toContain('will be refused')
        ->and($finding->remediation)->toContain('pg_dump 16 is older than the PostgreSQL 18 server');
});

it('only warns when a drop would go ahead without the backup', function (): void {
    config()->set('laranail.db-console.backup.abort_drop_on_failure', false);

    $finding = backupClientFindings(preflightingBackup(unusableClient()))[0];

    expect($finding->severity)->toBe(Severity::Warning)
        ->and($finding->message)->toContain('without a backup');
});

it('warns, rather than failing doctor, when the check itself cannot run', function (): void {
    $finding = backupClientFindings(preflightingBackup(null, new RuntimeException('no such server')))[0];

    expect($finding->severity)->toBe(Severity::Warning)
        ->and($finding->message)->toContain('Could not check the backup client')
        ->and($finding->message)->toContain('no such server');
});

it('is part of what doctor reports for a server', function (): void {
    config()->set('database.connections.lite_admin', ['driver' => 'sqlite', 'database' => ':memory:']);
    config()->set('laranail.db-console.servers', ['lite' => ['engine' => 'sqlite', 'connection' => 'lite_admin', 'tls' => ['enabled' => false]]]);
    app()->instance(BackupService::class, preflightingBackup(unusableClient()));

    $checks = array_map(static fn (DoctorFinding $finding): string => $finding->check, app(DoctorService::class)->checkServer('lite'));

    expect($checks)->toContain('server:lite:reachable')
        ->and($checks)->toContain('server:lite:backup-client');
});

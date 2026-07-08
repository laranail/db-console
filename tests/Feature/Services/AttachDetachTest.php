<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Gate;
use Simtabi\Laranail\DBConsole\Domain\Charset;
use Simtabi\Laranail\DBConsole\Domain\DbName;
use Simtabi\Laranail\DBConsole\Domain\Host;
use Simtabi\Laranail\DBConsole\Domain\Privileges\PrivilegeSet;
use Simtabi\Laranail\DBConsole\Domain\Username;
use Simtabi\Laranail\DBConsole\Enums\PrivilegePreset;
use Simtabi\Laranail\DBConsole\Services\AccountManager;
use Simtabi\Laranail\DBConsole\Services\DatabaseManager;
use Simtabi\Laranail\DBConsole\Services\PrivilegeManager;
use Simtabi\Laranail\DBConsole\Tests\Concerns\InteractsWithDockerServers;

uses(InteractsWithDockerServers::class);

beforeEach(function (): void {
    $this->registerMysqlServer();
    $this->migrateCatalog();
    Gate::before(fn ($user = null): bool => true);

    $this->suffix = $this->uniqueSuffix();
    $this->dbs = ["dbc_a_{$this->suffix}", "dbc_b_{$this->suffix}"];
    $this->user = "dbc_at_{$this->suffix}";

    $databases = app(DatabaseManager::class);
    foreach ($this->dbs as $db) {
        $databases->create('docker-mysql', new DbName($db), new Charset('utf8mb4'));
    }
    app(AccountManager::class)->create('docker-mysql', new Username($this->user), new Host('%'));
});

afterEach(function (): void {
    try {
        $conn = DB::connection('db_console_admin');
        foreach ($this->dbs as $db) {
            $conn->statement("DROP DATABASE IF EXISTS `{$db}`");
        }
        $conn->statement("DROP USER IF EXISTS '{$this->user}'@'%'");
    } catch (Throwable) {
    }
});

it('attaches one user to many databases and reports every pairing', function (): void {
    $result = app(PrivilegeManager::class)->attach(
        'docker-mysql',
        new Username($this->user),
        new Host('%'),
        array_map(fn (string $d): DbName => new DbName($d), $this->dbs),
        PrivilegeSet::fromPreset(PrivilegePreset::ReadWrite),
    );

    expect($result->total())->toBe(2)
        ->and($result->allSucceeded())->toBeTrue()
        ->and($result->succeeded())->toHaveCount(2);

    // Both grants are live on the server.
    $grants = implode("\n", app(PrivilegeManager::class)->showGrants('docker-mysql', new Username($this->user), new Host('%')));
    expect($grants)->toContain($this->dbs[0])->and($grants)->toContain($this->dbs[1]);
});

it('reports a partial batch — successful pairings commit, the failed one is named', function (): void {
    $privileges = app(PrivilegeManager::class);
    $user = new Username($this->user);
    $host = new Host('%');
    $set = PrivilegeSet::fromPreset(PrivilegePreset::ReadWrite);

    // Grant only on the first database, then detach BOTH. Revoking a grant
    // that was never made fails at the server (MySQL 1147), so the second
    // pairing fails while the first succeeds — a deterministic partial batch.
    $privileges->attach('docker-mysql', $user, $host, [new DbName($this->dbs[0])], $set);

    $result = $privileges->detach(
        'docker-mysql',
        $user,
        $host,
        [new DbName($this->dbs[0]), new DbName($this->dbs[1])],
        $set,
    );

    expect($result->total())->toBe(2)
        ->and($result->allSucceeded())->toBeFalse()
        ->and($result->succeeded())->toHaveCount(1)
        ->and($result->failed())->toHaveCount(1)
        ->and($result->succeeded()[0]['database'])->toBe($this->dbs[0])
        ->and($result->failed()[0]['database'])->toBe($this->dbs[1])
        ->and($result->failed()[0]['error'])->toBeString();
});

it('detaches a user from databases without dropping the user or databases', function (): void {
    $privileges = app(PrivilegeManager::class);
    $databases = array_map(fn (string $d): DbName => new DbName($d), $this->dbs);

    $privileges->attach('docker-mysql', new Username($this->user), new Host('%'), $databases, PrivilegeSet::fromPreset(PrivilegePreset::ReadWrite));
    $result = $privileges->detach('docker-mysql', new Username($this->user), new Host('%'), $databases, PrivilegeSet::fromPreset(PrivilegePreset::ReadWrite));

    expect($result->allSucceeded())->toBeTrue()
        // The account and databases still exist — only the grants were removed.
        ->and(app(AccountManager::class)->exists('docker-mysql', new Username($this->user), new Host('%')))->toBeTrue()
        ->and(app(DatabaseManager::class)->exists('docker-mysql', new DbName($this->dbs[0])))->toBeTrue();
});

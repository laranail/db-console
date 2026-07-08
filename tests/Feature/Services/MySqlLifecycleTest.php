<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Simtabi\Laranail\DBConsole\Domain\Charset;
use Simtabi\Laranail\DBConsole\Domain\DbName;
use Simtabi\Laranail\DBConsole\Domain\Host;
use Simtabi\Laranail\DBConsole\Domain\Password;
use Simtabi\Laranail\DBConsole\Domain\Privileges\PrivilegeSet;
use Simtabi\Laranail\DBConsole\Domain\Username;
use Simtabi\Laranail\DBConsole\Enums\OperationOutcome;
use Simtabi\Laranail\DBConsole\Enums\PrivilegePreset;
use Simtabi\Laranail\DBConsole\Events\AccountCreated;
use Simtabi\Laranail\DBConsole\Events\DatabaseCreated;
use Simtabi\Laranail\DBConsole\Events\PrivilegesGranted;
use Simtabi\Laranail\DBConsole\Models\Grant;
use Simtabi\Laranail\DBConsole\Models\ManagedDatabase;
use Simtabi\Laranail\DBConsole\Services\AccountManager;
use Simtabi\Laranail\DBConsole\Services\DatabaseManager;
use Simtabi\Laranail\DBConsole\Services\PrivilegeManager;
use Simtabi\Laranail\DBConsole\Tests\Concerns\InteractsWithDockerServers;

uses(InteractsWithDockerServers::class);

beforeEach(function (): void {
    $this->registerMysqlServer();
    $this->migrateCatalog();   // the real Eloquent catalog records what services do
    // A4 is not testing RBAC (that is A7). Allow every ability so the service
    // machinery is exercised; deny-by-default is verified in the RBAC suite.
    // The nullable param marks the callback as guest-callable.
    Gate::before(fn ($user = null): bool => true);

    $this->suffix = $this->uniqueSuffix();
    $this->db = "dbc_test_{$this->suffix}";
    $this->user = "dbc_u_{$this->suffix}";
});

afterEach(function (): void {
    // Best-effort cleanup so repeated local runs stay clean.
    try {
        $conn = DB::connection('db_console_admin');
        $conn->statement("DROP DATABASE IF EXISTS `{$this->db}`");
        $conn->statement("DROP USER IF EXISTS '{$this->user}'@'%'");
    } catch (Throwable) {
        // ignore
    }
});

it('creates a database live on the server and reports it', function (): void {
    $result = app(DatabaseManager::class)->create('docker-mysql', new DbName($this->db), new Charset('utf8mb4', 'utf8mb4_unicode_ci'));

    expect($result->outcome)->toBe(OperationOutcome::Succeeded)
        ->and($result->alreadyExisted)->toBeFalse()
        ->and(app(DatabaseManager::class)->exists('docker-mysql', new DbName($this->db)))->toBeTrue()
        ->and(app(DatabaseManager::class)->list('docker-mysql'))->toContain($this->db);
});

it('surfaces an already-existing database as a handled outcome, not a driver error', function (): void {
    $manager = app(DatabaseManager::class);
    $manager->create('docker-mysql', new DbName($this->db), new Charset('utf8mb4'));

    $again = $manager->create('docker-mysql', new DbName($this->db), new Charset('utf8mb4'));

    expect($again->alreadyExisted)->toBeTrue()
        ->and($again->outcome)->toBe(OperationOutcome::Succeeded);
});

it('runs the full create-database + account + grant lifecycle against real MySQL', function (): void {
    Event::fake([DatabaseCreated::class, AccountCreated::class, PrivilegesGranted::class]);

    $databases = app(DatabaseManager::class);
    $accounts = app(AccountManager::class);
    $privileges = app(PrivilegeManager::class);

    $databases->create('docker-mysql', new DbName($this->db), new Charset('utf8mb4', 'utf8mb4_unicode_ci'));

    $created = $accounts->create('docker-mysql', new Username($this->user), new Host('%'));
    // A generated password is returned exactly once.
    expect($created->hasGeneratedPassword())->toBeTrue()
        ->and($created->takeGeneratedPassword())->toBeString();

    $privileges->grant(
        'docker-mysql',
        new Username($this->user),
        new Host('%'),
        new DbName($this->db),
        PrivilegeSet::fromPreset(PrivilegePreset::AppStandard),
    );

    // The account exists live, and its grants include the granted database.
    expect($accounts->exists('docker-mysql', new Username($this->user), new Host('%')))->toBeTrue();

    $grants = $privileges->showGrants('docker-mysql', new Username($this->user), new Host('%'));
    expect(implode("\n", $grants))->toContain($this->db);

    Event::assertDispatched(DatabaseCreated::class);
    Event::assertDispatched(AccountCreated::class);
    Event::assertDispatched(PrivilegesGranted::class);

    // The catalog recorded the ownership history for what we did.
    expect(ManagedDatabase::query()
        ->where('server_name', 'docker-mysql')->where('name', $this->db)->exists())->toBeTrue()
        ->and(Grant::query()->count())->toBeGreaterThan(0);
});

it('rotates a password and drops an account, verifying live', function (): void {
    $accounts = app(AccountManager::class);
    $accounts->create('docker-mysql', new Username($this->user), new Host('%'), new Password('Xk9$mQ2vLpW7#nR4t!'));

    $rotated = $accounts->rotatePassword('docker-mysql', new Username($this->user), new Host('%'));
    expect($rotated->hasGeneratedPassword())->toBeTrue();

    $accounts->drop('docker-mysql', new Username($this->user), new Host('%'));
    expect($accounts->exists('docker-mysql', new Username($this->user), new Host('%')))->toBeFalse();
});

it('grants exactly the AppStandard privileges and nothing forbidden', function (): void {
    $databases = app(DatabaseManager::class);
    $accounts = app(AccountManager::class);
    $privileges = app(PrivilegeManager::class);

    $databases->create('docker-mysql', new DbName($this->db), new Charset('utf8mb4'));
    $accounts->create('docker-mysql', new Username($this->user), new Host('%'));
    $privileges->grant(
        'docker-mysql',
        new Username($this->user),
        new Host('%'),
        new DbName($this->db),
        PrivilegeSet::fromPreset(PrivilegePreset::AppStandard),
    );

    $lines = $privileges->showGrants('docker-mysql', new Username($this->user), new Host('%'));
    $shown = implode("\n", $lines);

    // The real privileges land on the target database, scoped, and never
    // self-escalating. MySQL always reports a baseline `GRANT USAGE ON *.*`
    // (the empty login grant); the only line touching *.* must be that USAGE
    // placeholder — DBConsole never grants real privileges server-wide.
    $serverWide = array_filter($lines, fn (string $line): bool => str_contains($line, '*.*'));

    expect($shown)->toContain('SELECT')
        ->and($shown)->toContain('INSERT')
        ->and($shown)->toContain("`{$this->db}`.*")   // grant is database-scoped
        ->and($shown)->not->toContain('GRANT OPTION') // never self-escalating
        ->and($serverWide)->toHaveCount(1)            // only the baseline line
        ->and(implode('', $serverWide))->toContain('USAGE');
});

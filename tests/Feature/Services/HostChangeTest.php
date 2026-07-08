<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Gate;
use Simtabi\Laranail\DBConsole\Domain\Charset;
use Simtabi\Laranail\DBConsole\Domain\DbName;
use Simtabi\Laranail\DBConsole\Domain\Host;
use Simtabi\Laranail\DBConsole\Domain\Password;
use Simtabi\Laranail\DBConsole\Domain\Privileges\PrivilegeSet;
use Simtabi\Laranail\DBConsole\Domain\Username;
use Simtabi\Laranail\DBConsole\Enums\PrivilegePreset;
use Simtabi\Laranail\DBConsole\Exceptions\DBConsoleException;
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
    $this->db = "dbc_hc_{$this->suffix}";
    $this->user = "dbc_hcu_{$this->suffix}";
    $this->password = 'Xk9$mQ2vLpW7#nR4t!';

    app(DatabaseManager::class)->create('docker-mysql', new DbName($this->db), new Charset('utf8mb4'));
    app(AccountManager::class)->create('docker-mysql', new Username($this->user), new Host('10.0.%'), new Password($this->password));
    app(PrivilegeManager::class)->grant(
        'docker-mysql',
        new Username($this->user),
        new Host('10.0.%'),
        new DbName($this->db),
        PrivilegeSet::fromPreset(PrivilegePreset::AppStandard),
    );
});

afterEach(function (): void {
    try {
        $conn = DB::connection('db_console_admin');
        $conn->statement("DROP DATABASE IF EXISTS `{$this->db}`");
        foreach (['10.0.%', '%'] as $h) {
            $conn->statement("DROP USER IF EXISTS '{$this->user}'@'{$h}'");
        }
    } catch (Throwable) {
    }
});

it('changes host as a grant-preserving recreate: new host exists, old is gone, grants survive', function (): void {
    $accounts = app(AccountManager::class);
    $user = new Username($this->user);

    $result = $accounts->changeHost('docker-mysql', $user, new Host('10.0.%'), new Host('%'));

    // The account now exists at the new host and no longer at the old one.
    expect($accounts->exists('docker-mysql', $user, new Host('%')))->toBeTrue()
        ->and($accounts->exists('docker-mysql', $user, new Host('10.0.%')))->toBeFalse();

    // The grants were re-applied to the new host.
    $grants = implode("\n", app(PrivilegeManager::class)->showGrants('docker-mysql', $user, new Host('%')));
    expect($grants)->toContain($this->db)
        ->and($grants)->toContain('SELECT')
        // No rotation requested → no generated password returned.
        ->and($result->hasGeneratedPassword())->toBeFalse();
});

it('preserves the password across a host change (login with the old password still works)', function (): void {
    app(AccountManager::class)->changeHost('docker-mysql', new Username($this->user), new Host('10.0.%'), new Host('%'));

    // A fresh connection as the moved account with its ORIGINAL password proves
    // the password was preserved (via the copied auth hash, never plaintext).
    $params = $this->mysqlParams();
    $pdo = new PDO(
        "mysql:host={$params['host']};port={$params['port']}",
        $this->user,
        $this->password,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
    );

    expect($pdo->query('SELECT 1')->fetchColumn())->toBe(1);
});

it('rotates the password on request and returns it once', function (): void {
    $result = app(AccountManager::class)->changeHost(
        'docker-mysql',
        new Username($this->user),
        new Host('10.0.%'),
        new Host('%'),
        rotate: true,
    );

    expect($result->hasGeneratedPassword())->toBeTrue()
        ->and($result->takeGeneratedPassword())->toBeString();

    // The new password logs in.
    $params = $this->mysqlParams();
    $pdo = new PDO(
        "mysql:host={$params['host']};port={$params['port']}",
        $this->user,
        (string) $result->takeGeneratedPassword(),
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
    );
    expect($pdo->query('SELECT 1')->fetchColumn())->toBe(1);
});

it('leaves the original account intact if a step fails (rollback)', function (): void {
    $accounts = app(AccountManager::class);
    $user = new Username($this->user);

    // Pre-create the target-host account so the create step collides and the
    // wizard rolls back — the original 10.0.% account must survive untouched.
    DB::connection('db_console_admin')->statement("CREATE USER '{$this->user}'@'%' IDENTIFIED BY 'someOtherPass1!'");

    expect(fn () => $accounts->changeHost('docker-mysql', $user, new Host('10.0.%'), new Host('%')))
        ->toThrow(DBConsoleException::class);

    // The original account is intact.
    expect($accounts->exists('docker-mysql', $user, new Host('10.0.%')))->toBeTrue();
});

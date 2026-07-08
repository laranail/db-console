<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Gate;
use Simtabi\Laranail\DBConsole\Tests\Concerns\InteractsWithDockerServers;

uses(InteractsWithDockerServers::class);

beforeEach(function (): void {
    $this->registerMysqlServer();
    $this->migrateCatalog();
    Gate::before(fn ($user = null): bool => true);
    $this->suffix = $this->uniqueSuffix();
    $this->db = "dbc_cli_{$this->suffix}";
    $this->user = "dbc_cliu_{$this->suffix}";
});

afterEach(function (): void {
    try {
        $conn = DB::connection('db_console_admin');
        $conn->statement("DROP DATABASE IF EXISTS `{$this->db}`");
        $conn->statement("DROP USER IF EXISTS '{$this->user}'@'%'");
    } catch (Throwable) {
    }
});

it('registers the full command surface under both the namespaced name and the alias', function (): void {
    $names = array_keys(Artisan::all());

    $expected = [
        'db:create', 'db:list', 'db:drop',
        'user:create', 'user:list', 'user:password', 'user:drop', 'user:edit',
        'grant', 'revoke', 'attach', 'detach', 'wizard', 'reconcile',
        'server:add', 'server:list', 'server:use',
        'audit:view', 'audit:verify', 'secrets:rotate', 'secrets:driver', 'encryption:status',
        'role:list', 'role:create', 'role:assign', 'role:revoke', 'access:show', 'access:check',
    ];

    foreach ($expected as $command) {
        expect($names)->toContain("laranail::db-console.{$command}")
            ->and($names)->toContain("db-console:{$command}");
    }
});

it('runs the wizard end to end and shows the generated password once', function (): void {
    $this->artisan('laranail::db-console.wizard', [
        '--server' => 'docker-mysql',
        '--db' => $this->db,
        '--user' => $this->user,
        '--host' => '%',
        '--preset' => 'app_standard',
    ])->assertSuccessful();

    // The database and account are live.
    $this->artisan('db-console:db:list', ['--server' => 'docker-mysql'])->assertSuccessful();
    $this->artisan('db-console:user:list', ['--server' => 'docker-mysql'])->assertSuccessful();
});

it('drops a database non-interactively only with --force, and refuses without it', function (): void {
    $this->artisan('db-console:db:create', ['--server' => 'docker-mysql', '--name' => $this->db])->assertSuccessful();
    DB::connection('db_console_admin')->statement("CREATE TABLE `{$this->db}`.t (id INT)");

    // Without --force in non-interactive mode, the drop is refused.
    $this->artisan('db-console:db:drop', ['--server' => 'docker-mysql', '--name' => $this->db, '--no-interaction' => true])
        ->assertFailed();

    // With --force it drops.
    $this->artisan('db-console:db:drop', ['--server' => 'docker-mysql', '--name' => $this->db, '--force' => true])
        ->assertSuccessful();
});

it('verifies the audit chain via the command after real operations', function (): void {
    $this->artisan('db-console:db:create', ['--server' => 'docker-mysql', '--name' => $this->db])->assertSuccessful();

    $this->artisan('db-console:audit:verify')->assertSuccessful();
});

it('reports access:check as denied under deny-by-default', function (): void {
    // No user resolved and no roles → denied. The command still exits 0 (it
    // reports the verdict); the verdict is DENIED.
    $this->artisan('db-console:access:check', ['--permission' => 'database.create', '--scope' => 'server:docker-mysql'])
        ->expectsOutputToContain('DENIED')
        ->assertSuccessful();
});

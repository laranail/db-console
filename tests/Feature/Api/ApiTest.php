<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Simtabi\Laranail\DBConsole\Tests\Concerns\InteractsWithDockerServers;
use Simtabi\Laranail\DBConsole\Tests\Fixtures\User;

uses(InteractsWithDockerServers::class);

beforeEach(function (): void {
    $this->registerMysqlServer();
    $this->migrateCatalog();
    Schema::create('users', function ($table): void {
        $table->increments('id');
        $table->string('name')->nullable();
        $table->timestamps();
    });
    config()->set('laranail.db-console.rbac.user_model', User::class);
    config()->set('laranail.db-console.api.prefix', 'api/db-console');

    // hasRoutesWhen evaluates the enabled flag at boot; enable + load the
    // routes now so they exist for the request. The ApiGuard still enforces
    // the enabled flag at request time (so the "disabled" case returns 404).
    config()->set('laranail.db-console.api.enabled', true);
    require dirname(__DIR__, 3) . '/routes/api.php';

    $this->suffix = $this->uniqueSuffix();
    $this->db = "dbc_api_{$this->suffix}";
});

afterEach(function (): void {
    try {
        DB::connection('db_console_admin')->statement("DROP DATABASE IF EXISTS `{$this->db}`");
    } catch (Throwable) {
    }
});

it('is unreachable (404) when the API is disabled — off by default', function (): void {
    config()->set('laranail.db-console.api.enabled', false);

    $this->getJson('/api/db-console/servers')->assertStatus(404);
});

it('requires authentication when enabled', function (): void {
    config()->set('laranail.db-console.api.enabled', true);
    config()->set('laranail.db-console.api.guard', 'web');

    $this->getJson('/api/db-console/servers')->assertStatus(401);
});

it('enforces the IP allow-list', function (): void {
    config()->set('laranail.db-console.api.enabled', true);
    config()->set('laranail.db-console.api.allowed_ips', ['10.9.9.9']);   // not the test client IP

    Gate::before(fn ($user = null): bool => true);
    $this->actingAs(User::query()->create(['name' => 'op']));

    $this->getJson('/api/db-console/servers')->assertStatus(403);
});

it('creates a database through the API when authorized (same Gate as CLI/UI)', function (): void {
    Gate::before(fn ($user = null): bool => true);
    $this->actingAs(User::query()->create(['name' => 'op']));

    $this->postJson('/api/db-console/servers/docker-mysql/databases', ['name' => $this->db])
        ->assertStatus(201)
        ->assertJsonPath('data.database', $this->db);

    $this->getJson('/api/db-console/servers/docker-mysql/databases')
        ->assertStatus(200)
        ->assertJsonFragment([$this->db]);
});

it('requires a matching confirm field to drop a database', function (): void {
    Gate::before(fn ($user = null): bool => true);
    $this->actingAs(User::query()->create(['name' => 'op']));

    $this->postJson('/api/db-console/servers/docker-mysql/databases', ['name' => $this->db])->assertStatus(201);

    // Wrong/absent confirm → 422, database still there.
    $this->deleteJson("/api/db-console/servers/docker-mysql/databases/{$this->db}", ['confirm' => 'wrong'])
        ->assertStatus(422);

    // Correct confirm → dropped.
    $this->deleteJson("/api/db-console/servers/docker-mysql/databases/{$this->db}", ['confirm' => $this->db])
        ->assertStatus(200);
});

it('denies an out-of-scope operator (RBAC parity with the CLI/UI)', function (): void {
    config()->set('laranail.db-console.api.enabled', true);
    // No Gate::before → deny-by-default; the operator has no assignment.
    $this->actingAs(User::query()->create(['name' => 'nobody']));

    $this->postJson('/api/db-console/servers/docker-mysql/databases', ['name' => $this->db])
        ->assertStatus(403);
});

<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;
use Illuminate\Contracts\Auth\Authenticatable;
use Simtabi\Laranail\DBConsole\Api\TokenManager;
use Simtabi\Laranail\DBConsole\Tests\Fixtures\User;
use Simtabi\Laranail\DBConsole\Enums\ConsolePermission;
use Simtabi\Laranail\DBConsole\Exceptions\NotAuthorized;
use Simtabi\Laranail\DBConsole\Access\Contracts\AccessManager;

/**
 * A stub AccessManager granting exactly the listed permissions at global
 * scope, so the token ceiling can be exercised without a full RBAC setup.
 */
function accessGranting(array $granted): AccessManager
{
    return new readonly class($granted) implements AccessManager
    {
        /** @param list<string> $granted */
        public function __construct(private array $granted) {}

        public function allows(?Authenticatable $user, ConsolePermission $permission, ?string $scope): bool
        {
            return in_array($permission->value, $this->granted, true);
        }

        public function authorize(?Authenticatable $user, ConsolePermission $permission, ?string $scope): void {}
    };
}

beforeEach(function (): void {
    $this->migrateCatalog();
    Schema::create('users', function ($table): void {
        $table->increments('id');
        $table->string('name')->nullable();
        $table->timestamps();
    });
    Schema::create('personal_access_tokens', function ($table): void {
        $table->increments('id');
        $table->morphs('tokenable');
        $table->string('name');
        $table->string('token', 64)->unique();
        $table->text('abilities')->nullable();
        $table->timestamp('last_used_at')->nullable();
        $table->timestamp('expires_at')->nullable();
        $table->timestamps();
    });
});

it('issues a token whose abilities equal the requested subset of what the operator holds', function (): void {
    $operator = User::query()->create(['name' => 'op']);
    $access = accessGranting([ConsolePermission::DatabaseView->value, ConsolePermission::DatabaseCreate->value]);
    $tokens = new TokenManager($access);

    $issued = $tokens->issue($operator, 'ci', [ConsolePermission::DatabaseView->value]);

    expect($issued['abilities'])->toBe([ConsolePermission::DatabaseView->value])
        ->and($issued['token'])->toBeString()
        ->and($issued['token'])->not->toBe('');
});

it('refuses to mint a token more powerful than the operator (abilities ceiling)', function (): void {
    $operator = User::query()->create(['name' => 'weak']);
    // Operator holds only DatabaseView; requesting DatabaseDrop must be rejected.
    $access = accessGranting([ConsolePermission::DatabaseView->value]);
    $tokens = new TokenManager($access);

    expect(fn (): array => $tokens->issue($operator, 'ci', [ConsolePermission::DatabaseDrop->value]))
        ->toThrow(NotAuthorized::class);
});

it('defaults an unspecified ability set to exactly the operator’s own permissions', function (): void {
    $operator = User::query()->create(['name' => 'op']);
    $granted = [ConsolePermission::DatabaseView->value, ConsolePermission::AuditView->value];
    $tokens = new TokenManager(accessGranting($granted));

    $issued = $tokens->issue($operator, 'ci', []);

    expect($issued['abilities'])->toEqualCanonicalizing($granted);
});

<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DBConsole\Tests\Fixtures;

use Illuminate\Contracts\Auth\Access\Authorizable as AuthorizableContract;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Auth\Access\Authorizable;
use Laravel\Sanctum\HasApiTokens;

/**
 * A minimal operator model for RBAC tests: an Authenticatable on the default
 * (testing) connection, so it can be an assignee of a RoleAssignment. It also
 * carries Sanctum's HasApiTokens so the API-token ceiling can be exercised.
 *
 * @property int $id
 */
final class User extends Model implements Authenticatable, AuthorizableContract
{
    use Authorizable;
    use HasApiTokens;

    protected $table = 'users';

    protected $guarded = [];

    public array $roles = [];

    public function getAuthIdentifierName(): string
    {
        return 'id';
    }

    public function getAuthIdentifier(): mixed
    {
        return $this->getKey();
    }

    public function getAuthPasswordName(): string
    {
        return 'password';
    }

    public function getAuthPassword(): string
    {
        return '';
    }

    public function getRememberToken(): string
    {
        return '';
    }

    public function setRememberToken($value): void {}

    public function getRememberTokenName(): string
    {
        return '';
    }
}

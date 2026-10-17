<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DBConsole\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\Notifiable;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Foundation\Auth\Access\Authorizable;
use Illuminate\Contracts\Auth\Access\Authorizable as AuthorizableContract;

/**
 * An operator with the two-factor columns laranail/authkit writes, and
 * Notifiable, for the operator-security tests.
 *
 * @property int $id
 */
final class Operator extends Model implements Authenticatable, AuthorizableContract
{
    use Authorizable;
    use Notifiable;

    protected $table = 'operators';

    protected $guarded = [];

    public $timestamps = false;

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

    public function routeNotificationForMail(): string
    {
        return "operator{$this->id}@example.test";
    }
}

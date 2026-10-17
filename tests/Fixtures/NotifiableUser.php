<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DBConsole\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\Notifiable;

/**
 * An operator with an in-app inbox, for the database notification channel.
 */
final class NotifiableUser extends Model
{
    use Notifiable;

    protected $table = 'users';

    protected $guarded = [];
}

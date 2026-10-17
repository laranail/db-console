<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DBConsole\Security;

use DateTimeInterface;
use Illuminate\Contracts\Auth\Authenticatable;
use Simtabi\Laranail\DBConsole\Contracts\OperatorAssurance;

/**
 * The default until the host binds its own: it knows of no second factor and
 * no recent confirmation, so any policy that needs either fails closed.
 */
final readonly class NullOperatorAssurance implements OperatorAssurance
{
    public function hasSecondFactor(Authenticatable $operator): bool
    {
        return false;
    }

    public function recentlyConfirmedAt(Authenticatable $operator): ?DateTimeInterface
    {
        return null;
    }
}

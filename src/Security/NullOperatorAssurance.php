<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DBConsole\Security;

use DateTimeInterface;
use Illuminate\Contracts\Auth\Authenticatable;
use Simtabi\Laranail\DBConsole\Contracts\OperatorAssurance;

/**
 * The default until the host binds its own: no second factor, never
 * confirmed. With enforcement on, every operation that requires assurance is
 * refused, and doctor says why.
 */
final class NullOperatorAssurance implements OperatorAssurance
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

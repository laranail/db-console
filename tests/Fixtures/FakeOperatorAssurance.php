<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DBConsole\Tests\Fixtures;

use DateTimeInterface;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Auth\Authenticatable;
use Simtabi\Laranail\DBConsole\Contracts\OperatorAssurance;

/**
 * A host's OperatorAssurance for the tests: reads `second_factor` and
 * `confirmed_at` straight off the Operator fixture.
 */
final class FakeOperatorAssurance implements OperatorAssurance
{
    public function hasSecondFactor(Authenticatable $operator): bool
    {
        return $operator instanceof Operator && (bool) $operator->getAttribute('second_factor');
    }

    public function recentlyConfirmedAt(Authenticatable $operator): ?DateTimeInterface
    {
        $at = $operator instanceof Operator ? $operator->getAttribute('confirmed_at') : null;

        return $at === null ? null : CarbonImmutable::parse((string) $at);
    }
}

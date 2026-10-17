<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DBConsole\Exceptions;

use DateTimeInterface;
use Simtabi\Laranail\DBConsole\Enums\ExceptionCode;

/**
 * Refused because the operator has not confirmed two-factor authentication
 * (with recovery codes) and `security.two_factor.enforce` is `require`. During
 * the enrolment grace window only writes are refused; after it, everything.
 */
final class TwoFactorRequired extends AuthorizationException
{
    public static function duringGrace(string $ability, DateTimeInterface $graceEndsAt): self
    {
        $ends = $graceEndsAt->format(DateTimeInterface::ATOM);

        return new self(
            message: "two-factor required for '{$ability}'; reads are allowed until {$ends}",
            userParams: ['ability' => $ability, 'status' => "Reads keep working until {$ends}; changes are refused now."],
            context: ['ability' => $ability, 'grace_ends_at' => $ends],
        );
    }

    public static function afterGrace(string $ability): self
    {
        return new self(
            message: "two-factor required for '{$ability}'; the enrolment grace window has ended",
            userParams: ['ability' => $ability, 'status' => 'The enrolment grace window has ended.'],
            context: ['ability' => $ability, 'grace_ends_at' => null],
        );
    }

    public function code(): ExceptionCode
    {
        return ExceptionCode::TwoFactorRequired;
    }
}

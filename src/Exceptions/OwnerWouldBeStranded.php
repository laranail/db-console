<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DBConsole\Exceptions;

use Simtabi\Laranail\DBConsole\Enums\ExceptionCode;

/**
 * Refused because the change would leave no global Owner able to act: the
 * last Owner revoked, or the last Owner with confirmed two-factor losing it
 * while enforcement is on.
 */
final class OwnerWouldBeStranded extends AuthorizationException
{
    public static function lastOwner(string $assignee): self
    {
        return new self(
            message: "revoking Owner from {$assignee} would leave no global Owner",
            userParams: ['reason' => 'this is the last global Owner'],
            context: ['assignee' => $assignee, 'reason' => 'last_owner'],
        );
    }

    public static function lastEnrolledOwner(string $assignee, string $change): self
    {
        return new self(
            message: "{$change} for {$assignee} would leave no global Owner with confirmed two-factor",
            userParams: ['reason' => "{$change} would leave no global Owner with confirmed two-factor"],
            context: ['assignee' => $assignee, 'reason' => 'last_enrolled_owner', 'change' => $change],
        );
    }

    public function code(): ExceptionCode
    {
        return ExceptionCode::OwnerWouldBeStranded;
    }
}

<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DBConsole\Exceptions;

use Simtabi\Laranail\DBConsole\Enums\ExceptionCode;

/**
 * The candidate password never appears in the message, params, or context.
 */
final class WeakPassword extends DomainException
{
    public function code(): ExceptionCode
    {
        return ExceptionCode::PasswordWeak;
    }

    public static function because(string $reason, int $minLength): self
    {
        return new self(
            message: "password rejected: {$reason}",
            userParams: ['reason' => $reason, 'min' => $minLength],
            context: ['reason' => $reason, 'min_length' => $minLength],
        );
    }
}

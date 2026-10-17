<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DBConsole\Exceptions;

use Simtabi\Laranail\DBConsole\Enums\ExceptionCode;

/**
 * The action needs the operator to have confirmed their identity recently
 * (a password, passkey or one-time code, as the host's OperatorAssurance
 * reports it), and they have not.
 */
final class ConfirmationRequired extends AuthorizationException
{
    public static function forAction(string $action, int $ttlSeconds): self
    {
        return new self(
            message: "{$action} needs an identity confirmation within the last {$ttlSeconds} seconds",
            userParams: ['action' => $action, 'seconds' => $ttlSeconds],
            context: ['action' => $action, 'ttl' => $ttlSeconds],
        );
    }

    public function code(): ExceptionCode
    {
        return ExceptionCode::ConfirmationRequired;
    }
}

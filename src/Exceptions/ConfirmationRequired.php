<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DBConsole\Exceptions;

use Simtabi\Laranail\DBConsole\Enums\ExceptionCode;

/**
 * The action needs the operator to have confirmed their identity recently
 * (a password, passkey or one-time code, as the host's OperatorAssurance
 * reports it), and they have not.
 *
 * @deprecated since the unreleased 0.1 line, removed no earlier than the next
 *             minor after 0.1. Nothing in db-console throws it any more: adopt_and_apply is
 *             decided by the assurance policy, which throws AssuranceRequired
 *             (assurance_required, with a `missing` list). Catch that instead,
 *             or their common parent AuthorizationException.
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

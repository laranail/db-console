<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DBConsole\Exceptions;

use Throwable;
use Simtabi\Laranail\DBConsole\Enums\ExceptionCode;

/**
 * A compensating action itself failed — the server may be in a partial
 * state a human must inspect. Always escalated: critical log + alert.
 */
final class RollbackFailed extends ExecutionException
{
    /**
     * @param array<string, mixed> $context
     */
    public static function whileCompensating(string $step, array $context = [], ?Throwable $previous = null): self
    {
        return new self(
            message: "compensating rollback failed at step '{$step}'; the server may be in a partial state",
            userParams: ['step' => $step],
            context: [...$context, 'step' => $step],
            previous: $previous,
        );
    }

    public function code(): ExceptionCode
    {
        return ExceptionCode::RollbackFailed;
    }
}

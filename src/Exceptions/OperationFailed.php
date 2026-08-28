<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DBConsole\Exceptions;

use Throwable;
use Simtabi\Laranail\DBConsole\Enums\ExceptionCode;

final class OperationFailed extends ExecutionException
{
    /**
     * @param array<string, mixed> $context sanitized driver detail for the log channel
     */
    public static function atServer(array $context = [], ?Throwable $previous = null): self
    {
        return new self(
            message: 'a statement failed at the server',
            userParams: [],
            context: $context,
            previous: $previous,
        );
    }

    public function code(): ExceptionCode
    {
        return ExceptionCode::OperationFailed;
    }
}

<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DBConsole\Exceptions;

use Simtabi\Laranail\DBConsole\Enums\ExceptionCode;
use Throwable;

final class OperationFailed extends ExecutionException
{
    public function code(): ExceptionCode
    {
        return ExceptionCode::OperationFailed;
    }

    /**
     * @param  array<string, mixed>  $context  sanitized driver detail for the log channel
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
}

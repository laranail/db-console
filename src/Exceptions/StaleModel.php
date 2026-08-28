<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DBConsole\Exceptions;

use Simtabi\Laranail\DBConsole\Enums\ExceptionCode;

/**
 * An optimistic-lock conflict: the catalog row was modified by someone else
 * between load and save. Surfaced instead of a silent overwrite (section 5).
 */
final class StaleModel extends ExecutionException
{
    public static function forRecord(string $table, string $key): self
    {
        return new self(
            message: "optimistic lock conflict on {$table}#{$key}: the record was modified by someone else",
            userParams: ['table' => $table],
            context: ['table' => $table, 'key' => $key],
        );
    }

    public function code(): ExceptionCode
    {
        return ExceptionCode::OperationFailed;
    }
}

<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DBConsole\Exceptions;

use Simtabi\Laranail\DBConsole\Enums\ExceptionCode;

final class UnsupportedOperation extends EngineException
{
    public function code(): ExceptionCode
    {
        return ExceptionCode::UnsupportedOperation;
    }

    public static function forEngine(string $engine, string $operation): self
    {
        return new self(
            message: "engine '{$engine}' does not support '{$operation}'",
            userParams: ['engine' => $engine, 'operation' => $operation],
            context: ['engine' => $engine, 'operation' => $operation],
        );
    }
}

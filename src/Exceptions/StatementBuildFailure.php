<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DBConsole\Exceptions;

use Simtabi\Laranail\DBConsole\Enums\ExceptionCode;

final class StatementBuildFailure extends EngineException
{
    public function code(): ExceptionCode
    {
        return ExceptionCode::StatementBuildFailure;
    }
}

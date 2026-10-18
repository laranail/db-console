<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DBConsole\Exceptions;

use Simtabi\Laranail\DBConsole\Enums\ExceptionCode;

/**
 * A custom privilege preset in config('laranail.db-console.presets') that the
 * allow-list refuses. Thrown at boot, so a bad preset stops the application
 * before it serves a request.
 */
final class PresetMisconfigured extends DomainException
{
    public static function named(string $preset, string $problem): self
    {
        return new self(
            message: "privilege preset '{$preset}' is misconfigured: {$problem}",
            userParams: ['preset' => $preset, 'problem' => $problem],
            context: ['preset' => $preset, 'problem' => $problem],
        );
    }

    public function code(): ExceptionCode
    {
        return ExceptionCode::PresetMisconfigured;
    }
}

<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DBConsole\Enums;

use Simtabi\Laranail\Enumerator\Attributes\Label;
use Simtabi\Laranail\Enumerator\Contracts\Enumerator;
use Simtabi\Laranail\Enumerator\Attributes\Description;
use Simtabi\Laranail\Enumerator\Contracts\Translatable;
use Simtabi\Laranail\DBConsole\Enums\Concerns\DBConsoleEnum;

/**
 * How `security.two_factor.enforce` treats an operator without confirmed
 * two-factor authentication. Unset, it resolves to `require` in production and
 * `report` everywhere else, so production is safe by default and every other
 * environment shows what enforcement would refuse before it is switched on.
 */
enum TwoFactorEnforcement: string implements Enumerator, Translatable
{
    use DBConsoleEnum;

    #[Label('Off'), Description('Two-factor is not checked. doctor warns.')]
    case Off = 'off';

    #[Label('Report'), Description('Nothing is refused; what enforcement would refuse is logged and alerted.')]
    case Report = 'report';

    #[Label('Require'), Description('Operators without confirmed two-factor are refused, after an enrolment grace window.')]
    case Require = 'require';

    /**
     * The configured mode, or the environment's default when the key is unset or empty.
     * An unrecognised value resolves to `require`: a typo must not switch enforcement off.
     */
    public static function resolve(mixed $configured, bool $production): self
    {
        if (! is_string($configured) || trim($configured) === '') {
            return $production ? self::Require : self::Report;
        }

        return self::tryFrom(strtolower(trim($configured))) ?? self::Require;
    }
}

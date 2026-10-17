<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DBConsole\Enums;

use Simtabi\Laranail\Enumerator\Attributes\Label;
use Simtabi\Laranail\Enumerator\Contracts\Enumerator;
use Simtabi\Laranail\Enumerator\Attributes\Description;
use Simtabi\Laranail\Enumerator\Contracts\Translatable;
use Simtabi\Laranail\DBConsole\Enums\Concerns\DBConsoleEnum;

/**
 * How `security.assurance.enforce` treats an operator whose host-reported
 * assurance falls short of what an operation requires. Unset, it resolves to
 * `require` in production and `report` everywhere else, so production is safe
 * by default and every other environment shows what would be refused first.
 */
enum AssuranceEnforcement: string implements Enumerator, Translatable
{
    use DBConsoleEnum;

    #[Label('Off'), Description('Assurance is not checked. doctor errors in production and warns elsewhere.')]
    case Off = 'off';

    #[Label('Report'), Description('Nothing is refused; each shortfall is audited, and alerted once a day per operator.')]
    case Report = 'report';

    #[Label('Require'), Description('An operation is refused until the operator has the assurance it requires.')]
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

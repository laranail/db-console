<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DBConsole\Enums;

use Simtabi\Laranail\Enumerator\Attributes\Label;
use Simtabi\Laranail\Enumerator\Contracts\Enumerator;
use Simtabi\Laranail\Enumerator\Contracts\Translatable;
use Simtabi\Laranail\DBConsole\Enums\Concerns\DBConsoleEnum;

/**
 * Error/event severity. Drives both the log level and whether an alert
 * fires: warnings are security signals (root-like admin found) and
 * criticals (destructive failure, failed rollback) always alert.
 */
enum Severity: string implements Enumerator, Translatable
{
    use DBConsoleEnum;

    #[Label('Info')]
    case Info = 'info';

    #[Label('Notice')]
    case Notice = 'notice';

    #[Label('Warning')]
    case Warning = 'warning';

    #[Label('Error')]
    case Error = 'error';

    #[Label('Critical')]
    case Critical = 'critical';

    /**
     * The PSR-3 log level for this severity.
     */
    public function psrLevel(): string
    {
        return $this->value;
    }

    /**
     * Whether events at this severity raise an alert on the alert channel.
     */
    public function alerts(): bool
    {
        return $this === self::Warning || $this === self::Critical;
    }
}

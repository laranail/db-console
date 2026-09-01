<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DBConsole\Enums;

use Simtabi\Laranail\DBConsole\Enums\Concerns\DBConsoleEnum;
use Simtabi\Laranail\Enumerator\Attributes\Label;
use Simtabi\Laranail\Enumerator\Contracts\Enumerator;
use Simtabi\Laranail\Enumerator\Contracts\Translatable;

/**
 * Notification routing category — maps to the recipients lists in
 * config('laranail.db-console.notifications.recipients').
 */
enum NotificationCategory: string implements Enumerator, Translatable
{
    use DBConsoleEnum;

    #[Label('Routine')]
    case Routine = 'routine';

    #[Label('Destructive')]
    case Destructive = 'destructive';

    #[Label('Security')]
    case Security = 'security';
}

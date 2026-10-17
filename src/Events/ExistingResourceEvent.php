<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DBConsole\Events;

use Simtabi\Laranail\DBConsole\Enums\NotificationCategory;
use Simtabi\Laranail\DBConsole\Events\Contracts\CategorizesNotification;

/**
 * Base of the events about an account or database that was already there: a
 * refusal, an adoption, a password applied to an adopted account. Each acts
 * on (or declines to act on) something db-console did not create, so each
 * goes to the security recipients.
 */
abstract class ExistingResourceEvent extends DBConsoleEvent implements CategorizesNotification
{
    public function notificationCategory(): NotificationCategory
    {
        return NotificationCategory::Security;
    }
}

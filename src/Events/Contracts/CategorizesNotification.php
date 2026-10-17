<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DBConsole\Events\Contracts;

use Simtabi\Laranail\DBConsole\Enums\NotificationCategory;

/**
 * An event that names its own notification category instead of having it
 * inferred from its severity and operation. The existing-resource events use
 * it so a refusal or an adoption always reaches the security recipients.
 */
interface CategorizesNotification
{
    public function notificationCategory(): NotificationCategory;
}

<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DBConsole\Notifications;

use Simtabi\Laranail\DBConsole\Enums\OperationType;

/**
 * A create found an account or database already there: it was refused, or an
 * authorised operator adopted it, or gave an adopted account a new password.
 * Sent to the security recipients, because each touches (or declined to
 * touch) something db-console did not create.
 */
final class ExistingResourceNotification extends DBConsoleNotification
{
    protected function subject(): string
    {
        return match ($this->event->operation()) {
            OperationType::AccountCreationRefused, OperationType::DatabaseCreationRefused => 'db-console: refused to take over an existing resource',
            OperationType::AccountPasswordApplied                                         => 'db-console: an adopted account was given a new password',
            default                                                                       => 'db-console: an existing resource was adopted',
        };
    }

    protected function body(): string
    {
        return match ($this->event->operation()) {
            OperationType::AccountCreationRefused, OperationType::DatabaseCreationRefused => 'A create found the resource already there. Nothing was changed. An operator holding resource.adopt can adopt it explicitly.',
            OperationType::AccountPasswordApplied                                         => 'The password of an account db-console did not create was replaced. Anything still using the old password can no longer log in.',
            default                                                                       => 'An operator adopted a resource db-console did not create. It is now managed as it was found; its password was not changed.',
        };
    }
}

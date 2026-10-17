<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DBConsole\Notifications;

use Override;
use Illuminate\Notifications\Messages\MailMessage;
use Simtabi\Laranail\DBConsole\Events\TwoFactorEnforced;

/**
 * Sent to the operator themself when enforcement first refuses them (or, in
 * report mode, would have): what is refused, until when reads keep working,
 * and that recovery codes are part of enrolling.
 */
final class TwoFactorEnrolmentNotification extends DBConsoleNotification
{
    public function __construct(private readonly TwoFactorEnforced $enforced)
    {
        parent::__construct($enforced);
    }

    #[Override]
    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject($this->subject())
            ->line($this->body());

        if ($this->enforced->verdict === TwoFactorEnforced::WOULD_REFUSE) {
            return $mail->line('Nothing is refused yet: enforcement is in report mode. Enrol before it is switched on.');
        }

        return $this->enforced->graceEndsAt !== null
            ? $mail->line("Changes are refused now. Read access continues until {$this->enforced->graceEndsAt}.")
            : $mail->line('The enrolment grace window has ended, so every request is refused until you enrol.');
    }

    protected function subject(): string
    {
        return 'db-console: enrol in two-factor authentication';
    }

    protected function body(): string
    {
        return 'db-console requires two-factor authentication. Enrol in your account settings and keep the recovery codes it gives you: they are what gets you back in if you lose the device.';
    }
}

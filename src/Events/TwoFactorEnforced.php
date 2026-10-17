<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DBConsole\Events;

use Override;
use Simtabi\Laranail\DBConsole\Enums\Severity;
use Simtabi\Laranail\DBConsole\Enums\OperationType;

/**
 * An operator without two-factor was refused, or (in `report` mode) would have
 * been. Raised once per operator per day, not per request, so an operator who
 * has not enrolled yet does not flood the security inbox.
 */
final class TwoFactorEnforced extends DBConsoleEvent
{
    public const string REFUSED = 'refused';

    public const string WOULD_REFUSE = 'would_refuse';

    /**
     * @param array<string, mixed> $context
     */
    public function __construct(
        public readonly string $operator,
        public readonly string $verdict,
        public readonly string $ability,
        public readonly ?string $graceEndsAt,
        array $context = [],
    ) {
        parent::__construct('global', [
            ...$context,
            'target'        => $operator,
            'verdict'       => $verdict,
            'ability'       => $ability,
            'grace_ends_at' => $graceEndsAt,
        ]);
    }

    public function operation(): OperationType
    {
        return OperationType::SecurityTwoFactor;
    }

    #[Override]
    public function severity(): Severity
    {
        return Severity::Warning;
    }
}

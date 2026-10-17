<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DBConsole\Events;

use Override;
use Simtabi\Laranail\DBConsole\Enums\Severity;
use Simtabi\Laranail\DBConsole\Enums\OperationType;

/**
 * Someone at the host's shell issued a break-glass bypass. Always critical:
 * it lifts two-factor for an operator, so the security recipients hear about
 * it whether or not it was expected.
 */
final class BreakGlassIssued extends DBConsoleEvent
{
    /**
     * @param array<string, mixed> $context
     */
    public function __construct(
        public readonly string $operator,
        public readonly string $expiresAt,
        public readonly ?string $issuedBy,
        array $context = [],
    ) {
        parent::__construct('global', [
            ...$context,
            'target'     => $operator,
            'expires_at' => $expiresAt,
            'issued_by'  => $issuedBy,
        ]);
    }

    public function operation(): OperationType
    {
        return OperationType::SecurityBreakGlass;
    }

    #[Override]
    public function severity(): Severity
    {
        return Severity::Critical;
    }
}

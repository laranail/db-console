<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DBConsole\Events;

use Override;
use Simtabi\Laranail\DBConsole\Enums\Severity;
use Simtabi\Laranail\DBConsole\Enums\OperationType;

/**
 * An operator lacked the assurance an operation requires: refused (`require`)
 * or let through and recorded (`report`). Every one is audited; one per
 * operator per day is raised at warning severity so the security recipients
 * hear about it without being flooded.
 */
final class AssuranceShortfall extends DBConsoleEvent
{
    public const string REFUSED = 'refused';

    public const string WOULD_REFUSE = 'would_refuse';

    /**
     * @param list<string> $missing
     * @param array<string, mixed> $context
     */
    public function __construct(
        string $server,
        public readonly string $operator,
        public readonly string $verdict,
        public readonly string $ability,
        public readonly array $missing,
        public readonly bool $alert,
        array $context = [],
    ) {
        parent::__construct($server, [
            ...$context,
            'target'  => $operator,
            'verdict' => $verdict,
            'ability' => $ability,
            'missing' => $missing,
        ]);
    }

    public function operation(): OperationType
    {
        return OperationType::SecurityAssurance;
    }

    #[Override]
    public function severity(): Severity
    {
        return $this->alert ? Severity::Warning : Severity::Notice;
    }
}

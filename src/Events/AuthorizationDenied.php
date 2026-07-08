<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DBConsole\Events;

use Override;
use Simtabi\Laranail\DBConsole\Enums\OperationType;
use Simtabi\Laranail\DBConsole\Enums\Severity;

/**
 * A gate denied a requested operation. Recorded so "who tried to do what
 * without access" is answerable — which matters as much as recording what
 * succeeded (section 20). The ability and scope are the detail; no secret is
 * involved.
 */
final class AuthorizationDenied extends DBConsoleEvent
{
    /**
     * @param  array<string, mixed>  $context
     */
    public function __construct(
        string $server,
        public readonly string $ability,
        array $context = [],
    ) {
        parent::__construct($server, [...$context, 'ability' => $ability, 'target' => $ability]);
    }

    public function operation(): OperationType
    {
        return OperationType::ServerSwitched;
    }

    #[Override]
    public function severity(): Severity
    {
        return Severity::Notice;
    }
}

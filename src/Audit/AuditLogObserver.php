<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DBConsole\Audit;

use RuntimeException;

/**
 * Makes the audit trail append-only by construction: any attempt to update
 * or delete a persisted audit row is blocked, so the compliance record can
 * only ever grow. Combined with the hash chain, this makes tampering both
 * refused (here) and detectable (AuditChain::verify) — belt and suspenders.
 */
final class AuditLogObserver
{
    public function updating(): bool
    {
        throw new RuntimeException('the DBConsole audit trail is append-only; audit rows cannot be modified');
    }

    public function deleting(): bool
    {
        throw new RuntimeException('the DBConsole audit trail is append-only; audit rows cannot be deleted');
    }
}

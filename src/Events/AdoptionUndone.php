<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DBConsole\Events;

use Override;
use Simtabi\Laranail\DBConsole\Enums\Severity;
use Simtabi\Laranail\DBConsole\Enums\OperationType;

/**
 * A wizard run adopted an account or database (`kind` in the context) and
 * then failed, so its rollback took the adoption back: the resource is no
 * longer recorded as managed. Nothing changed on the server; the resource is
 * as it was before the run.
 */
final class AdoptionUndone extends ExistingResourceEvent
{
    public function operation(): OperationType
    {
        return OperationType::AdoptionUndone;
    }

    #[Override]
    public function severity(): Severity
    {
        return Severity::Notice;
    }
}

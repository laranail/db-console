<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DBConsole\Engines;

use Simtabi\Laranail\DBConsole\Domain\Password;
use Simtabi\Laranail\DBConsole\Exceptions\WeakPassword;

/**
 * An engine that cannot take every password Password accepts. Postgres is
 * that engine: it sends a SCRAM verifier, and a password SASLprep would
 * rewrite has no verifier a login would match. Callers ask before they
 * change anything, so a refusal leaves nothing behind to roll back.
 */
interface PasswordConstrainedEngine
{
    /**
     * @throws WeakPassword when the engine cannot use $p
     */
    public function assertAcceptsPassword(Password $p): void;
}

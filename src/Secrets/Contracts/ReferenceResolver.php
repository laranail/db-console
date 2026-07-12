<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DBConsole\Secrets\Contracts;

use Simtabi\Laranail\DBConsole\Secrets\Secret;

/**
 * Resolves a bare pointer into an external secrets manager (AWS Secrets
 * Manager, Vault, Doppler) to the live credential at use-time. The
 * ReferenceVault stores nothing decryptable — only the pointer — so a
 * resolver is the only way to obtain the value, and it never persists it.
 */
interface ReferenceResolver
{
    /**
     * Fetch the current secret behind a pointer. The pointer format is the
     * resolver's own (an ARN, a Vault path, a Doppler config/name).
     */
    public function resolve(string $pointer): Secret;

    /**
     * The provider identifier for doctor/audit reporting.
     */
    public function provider(): string;
}

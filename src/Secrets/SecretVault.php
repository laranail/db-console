<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DBConsole\Secrets;

/**
 * The single seam through which every admin credential flows, so callers
 * never touch a raw secret or know which backend is active (section 8).
 *
 * The security property that matters is where the decryption key lives
 * relative to the ciphertext — that is the difference between the drivers,
 * not the cipher. See SecretDriver for the tradeoffs.
 */
interface SecretVault
{
    /**
     * Persist a secret (or register a reference to one) under an opaque
     * handle. The handle is what the catalog stores; it never contains the
     * secret.
     */
    public function store(string $ref, Secret $secret): void;

    /**
     * Resolve the secret at use-time. Throws SecretUnavailable when the
     * backend cannot produce it (which is treated as high-severity).
     */
    public function reveal(string $ref): Secret;

    public function forget(string $ref): void;

    /**
     * Replace the secret under an existing handle, re-wrapping/rotating per
     * the driver's model.
     */
    public function rotate(string $ref, Secret $new): void;

    /**
     * The active driver name, for doctor and audit reporting.
     */
    public function driver(): string;
}

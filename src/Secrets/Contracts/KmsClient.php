<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DBConsole\Secrets\Contracts;

/**
 * The seam over an external KMS (AWS KMS / GCP KMS). KmsVault does all the
 * envelope-encryption logic locally and uses this only to wrap/unwrap the
 * per-secret data key. Wrapping and unwrapping a data key each require a
 * live, separately-authenticated call to the KMS — which is exactly the
 * security property that makes catalog theft insufficient.
 *
 * Implementations are gated on the relevant optional SDK; when the SDK is
 * absent the driver reports SecretDriverMisconfigured with the fix rather
 * than failing obscurely.
 */
interface KmsClient
{
    /**
     * Encrypt (wrap) a plaintext data key under the configured KMS key.
     * Returns the opaque wrapped-key blob to persist.
     */
    public function wrap(string $plaintextDataKey): string;

    /**
     * Decrypt (unwrap) a previously wrapped data key. Returns the plaintext
     * data key for local decryption.
     */
    public function unwrap(string $wrappedDataKey): string;

    /**
     * The provider identifier (aws|gcp) for doctor/audit reporting.
     */
    public function provider(): string;
}

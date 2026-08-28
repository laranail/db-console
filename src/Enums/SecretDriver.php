<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DBConsole\Enums;

use Simtabi\Laranail\Enumerator\Attributes\Label;
use Simtabi\Laranail\Enumerator\Contracts\Enumerator;
use Simtabi\Laranail\Enumerator\Attributes\Description;
use Simtabi\Laranail\Enumerator\Contracts\Translatable;
use Simtabi\Laranail\DBConsole\Enums\Concerns\DBConsoleEnum;

/**
 * Where the decryption key for stored admin credentials lives — THE
 * security-critical choice. Ascending in strength: app_key keeps the key on
 * the same box as the ciphertext; kms/vault need a live, separately
 * authenticated call; reference stores nothing decryptable at all.
 */
enum SecretDriver: string implements Enumerator, Translatable
{
    use DBConsoleEnum;

    #[Label('App key'), Description('Ciphertext in the catalog, key is APP_KEY in .env on the same box. Localhost/single-box only — never for brokering real production credentials.')]
    case AppKey = 'app_key';

    #[Label('KMS'), Description('Envelope encryption: the data key is wrapped by an external KMS (AWS/GCP); decrypting needs a live, audited KMS call.')]
    case Kms = 'kms';

    #[Label('Vault'), Description('The secret lives in HashiCorp Vault; the catalog holds only a path reference resolved at use-time.')]
    case Vault = 'vault';

    #[Label('Reference'), Description('Nothing decryptable is stored: the catalog holds a pointer into an external secrets manager and the credential is fetched fresh each use.')]
    case Reference = 'reference';

    /**
     * Whether this driver keeps its decryption key on the same box as the
     * ciphertext — the property that makes it unfit for production.
     */
    public function keyLivesWithCiphertext(): bool
    {
        return $this === self::AppKey;
    }
}

<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DBConsole\Secrets\Drivers;

use Throwable;
use Illuminate\Contracts\Encryption\Encrypter;
use Simtabi\Laranail\DBConsole\Secrets\Secret;
use Simtabi\Laranail\DBConsole\Enums\SecretDriver;
use Simtabi\Laranail\DBConsole\Secrets\SecretVault;
use Simtabi\Laranail\DBConsole\Exceptions\SecretUnavailable;
use Simtabi\Laranail\DBConsole\Secrets\Contracts\SecretStore;

/**
 * The canonical driver: ciphertext in the catalog, the key is APP_KEY in
 * .env on the SAME box. Honest weakness — catalog plus .env together is
 * game over — so it is for localhost/single-box use and is blocked in
 * production without an explicit override (enforced by SecretVaultManager,
 * not here).
 */
final readonly class AppKeyVault implements SecretVault
{
    public function __construct(
        private Encrypter $encrypter,
        private SecretStore $store,
    ) {}

    public function store(string $ref, Secret $secret): void
    {
        // serialize: false — a raw string, so the ciphertext never embeds a
        // PHP-serialized payload that could be abused on decrypt.
        $this->store->put($ref, $this->encrypter->encrypt($secret->reveal(), false));
    }

    public function reveal(string $ref): Secret
    {
        $payload = $this->store->get($ref);
        if ($payload === null) {
            throw SecretUnavailable::forReference($ref, $this->driver());
        }

        try {
            // Throws DecryptException (a Throwable) on tamper/wrong key.
            $plaintext = $this->encrypter->decrypt($payload, false);

            return new Secret(is_string($plaintext) ? $plaintext : '');
        } catch (Throwable $e) {
            throw SecretUnavailable::forReference($ref, $this->driver(), $e);
        }
    }

    public function forget(string $ref): void
    {
        $this->store->forget($ref);
    }

    public function rotate(string $ref, Secret $new): void
    {
        // Re-encrypt under the current APP_KEY (which may have just been
        // rotated); overwrite the ciphertext.
        $this->store($ref, $new);
    }

    public function driver(): string
    {
        return SecretDriver::AppKey->value;
    }
}

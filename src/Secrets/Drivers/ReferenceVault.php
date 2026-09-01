<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DBConsole\Secrets\Drivers;

use Simtabi\Laranail\DBConsole\Enums\SecretDriver;
use Simtabi\Laranail\DBConsole\Exceptions\SecretUnavailable;
use Simtabi\Laranail\DBConsole\Secrets\Contracts\ReferenceResolver;
use Simtabi\Laranail\DBConsole\Secrets\Contracts\SecretStore;
use Simtabi\Laranail\DBConsole\Secrets\Secret;
use Simtabi\Laranail\DBConsole\Secrets\SecretVault;
use Throwable;

/**
 * The highest-assurance driver: NOTHING decryptable is stored. The catalog
 * holds only a pointer into an external secrets manager, and the real
 * credential is fetched fresh each use and never persisted by DBConsole.
 * Even if the whole catalog and .env leak, no working password can be
 * reconstructed from them.
 *
 * store() therefore does not accept a secret to persist — the credential
 * already lives externally. It registers the pointer (passed as the Secret
 * body, which here is a non-sensitive external reference, not a password).
 */
final readonly class ReferenceVault implements SecretVault
{
    public function __construct(
        private ReferenceResolver $resolver,
        private SecretStore $store,
    ) {}

    /**
     * Register the external pointer for this handle. The "secret" here is
     * the pointer string (an ARN / Vault path / Doppler ref), which is not
     * itself a credential.
     */
    public function store(string $ref, Secret $pointer): void
    {
        $this->store->put($ref, $pointer->reveal());
    }

    public function reveal(string $ref): Secret
    {
        $pointer = $this->store->get($ref);
        if ($pointer === null) {
            throw SecretUnavailable::forReference($ref, $this->driver());
        }

        try {
            return $this->resolver->resolve($pointer);
        } catch (SecretUnavailable $e) {
            throw $e;
        } catch (Throwable $e) {
            throw SecretUnavailable::forReference($ref, $this->driver(), $e);
        }
    }

    public function forget(string $ref): void
    {
        // Only the pointer is dropped; DBConsole never owned the credential.
        $this->store->forget($ref);
    }

    public function rotate(string $ref, Secret $newPointer): void
    {
        // Rotation of a referenced credential happens in the external
        // manager; here we only update the pointer if it changed.
        $this->store($ref, $newPointer);
    }

    public function driver(): string
    {
        return SecretDriver::Reference->value;
    }
}

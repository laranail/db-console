<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DBConsole\Secrets;

use Illuminate\Contracts\Events\Dispatcher;
use Simtabi\Laranail\DBConsole\Enums\OperationType;
use Simtabi\Laranail\DBConsole\Events\SecretsRotated;
use Simtabi\Laranail\DBConsole\Secrets\Contracts\SecretStore;

/**
 * secrets:rotate — re-wrap every stored secret under the active driver's new
 * key material (section 8): re-encrypt under a rotated APP_KEY (app_key),
 * re-wrap under a new data key (kms), or write a new version
 * (vault/reference). The secret VALUE is unchanged; only its protection is
 * refreshed. Runs through the same SecretVault seam, so it never touches a
 * raw secret or knows the backend.
 */
final readonly class SecretRotator
{
    public function __construct(
        private SecretVault $vault,
        private SecretStore $store,
        private Dispatcher $events,
    ) {}

    /**
     * Rotate every stored secret. Returns the number rotated.
     */
    public function rotateAll(): int
    {
        $count = 0;
        foreach ($this->store->keys() as $ref) {
            // reveal (decrypts with the current/previous key) → rotate
            // (re-wraps under the new key), value preserved.
            $secret = $this->vault->reveal($ref);
            $this->vault->rotate($ref, $secret);
            $count++;
        }

        $this->events->dispatch(new SecretsRotated('global', [
            'target'  => $this->vault->driver(),
            'rotated' => $count,
        ]));

        return $count;
    }

    public function operation(): OperationType
    {
        return OperationType::SecretsRotated;
    }
}

<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DBConsole\Secrets\Stores;

use Simtabi\Laranail\DBConsole\Secrets\Contracts\SecretStore;

/**
 * In-memory secret store for tests and ephemeral use. Never used in a real
 * deployment — the service provider binds DatabaseSecretStore.
 */
final class ArraySecretStore implements SecretStore
{
    /** @var array<string, string> */
    private array $items = [];

    public function put(string $ref, string $payload): void
    {
        $this->items[$ref] = $payload;
    }

    public function get(string $ref): ?string
    {
        return $this->items[$ref] ?? null;
    }

    public function forget(string $ref): void
    {
        unset($this->items[$ref]);
    }

    public function has(string $ref): bool
    {
        return array_key_exists($ref, $this->items);
    }

    public function keys(): array
    {
        return array_keys($this->items);
    }
}

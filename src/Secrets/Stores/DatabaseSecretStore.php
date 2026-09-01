<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DBConsole\Secrets\Stores;

use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\Query\Builder;
use Simtabi\Laranail\DBConsole\Secrets\Contracts\SecretStore;

/**
 * The production secret store: a single table on the encrypted catalog
 * connection. The payload column holds whatever the active driver produced
 * (ciphertext or a bare pointer); under app_key that column is the
 * column-encrypted admin secret, under kms/vault/reference it is a wrapped
 * key or a pointer with nothing plaintext-recoverable.
 */
final readonly class DatabaseSecretStore implements SecretStore
{
    public function __construct(
        private ConnectionResolverInterface $connections,
        private string $connection,
        private string $table,
    ) {}

    public function put(string $ref, string $payload): void
    {
        $this->query()->updateOrInsert(
            ['ref' => $ref],
            ['payload' => $payload, 'updated_at' => now()],
        );
    }

    public function get(string $ref): ?string
    {
        $value = $this->query()->where('ref', $ref)->value('payload');

        return is_string($value) ? $value : null;
    }

    public function forget(string $ref): void
    {
        $this->query()->where('ref', $ref)->delete();
    }

    public function has(string $ref): bool
    {
        return $this->query()->where('ref', $ref)->exists();
    }

    public function keys(): array
    {
        return array_values(array_map(strval(...), $this->query()->pluck('ref')->all()));
    }

    private function query(): Builder
    {
        return $this->connections->connection($this->connection)->table($this->table);
    }
}

<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DBConsole\Models\Casts;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;

/**
 * A topology column (a server's host, an account's username and host),
 * encrypted at rest with the model's encrypter while
 * catalog_encryption.encrypt_topology_columns is true (the default), and
 * stored as written while it is false.
 *
 * Reads take either form, so a row written under one setting stays readable
 * under the other: a value shaped like an encrypted payload is decrypted,
 * and fails as Laravel's own `encrypted` cast does when it cannot be; any
 * other value is plaintext and returned as stored.
 *
 * @implements CastsAttributes<string|null, string|null>
 */
final class TopologyColumn implements CastsAttributes
{
    public static function encrypts(): bool
    {
        return (bool) config('laranail.db-console.catalog_encryption.encrypt_topology_columns', true);
    }

    /**
     * @param array<string, mixed> $attributes
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = (string) $value;

        return $this->isEncryptedPayload($value)
            ? (string) $model::currentEncrypter()->decrypt($value, false)
            : $value;
    }

    /**
     * @param array<string, mixed> $attributes
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = (string) $value;

        return self::encrypts() ? $model::currentEncrypter()->encrypt($value, false) : $value;
    }

    /**
     * The shape Illuminate\Encryption\Encrypter writes: base64 of a JSON
     * object carrying string iv, value and mac.
     */
    private function isEncryptedPayload(string $value): bool
    {
        $decoded = base64_decode($value, true);
        if ($decoded === false) {
            return false;
        }

        $payload = json_decode($decoded, true);
        if (! is_array($payload)) {
            return false;
        }

        return array_all(['iv', 'value', 'mac'], fn (string $field): bool => isset($payload[$field]) && is_string($payload[$field]));
    }
}

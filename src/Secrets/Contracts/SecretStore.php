<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DBConsole\Secrets\Contracts;

/**
 * The persistence backing a SecretVault: an opaque handle → opaque payload
 * blob store. What the payload contains is the driver's business — for
 * app_key/kms it is ciphertext, for reference it is a bare pointer, for
 * vault it is the Vault path. The store never interprets it.
 *
 * The default implementation writes to the encrypted catalog connection.
 */
interface SecretStore
{
    public function put(string $ref, string $payload): void;

    public function get(string $ref): ?string;

    public function forget(string $ref): void;

    public function has(string $ref): bool;

    /**
     * Every stored handle — used by secrets:rotate to re-wrap them all.
     *
     * @return list<string>
     */
    public function keys(): array;
}

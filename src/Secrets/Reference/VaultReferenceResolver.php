<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DBConsole\Secrets\Reference;

use Simtabi\Laranail\DBConsole\Secrets\Secret;
use Simtabi\Laranail\DBConsole\Secrets\Drivers\HashiCorpVault;
use Simtabi\Laranail\DBConsole\Secrets\Contracts\ReferenceResolver;

/**
 * Resolves a Vault pointer (a KV path) by reading it through the same
 * HashiCorpVault client used by the vault driver — reference mode simply
 * never writes, only reads what already lives in Vault.
 */
final readonly class VaultReferenceResolver implements ReferenceResolver
{
    public function __construct(private HashiCorpVault $vault) {}

    public function resolve(string $pointer): Secret
    {
        return $this->vault->reveal($pointer);
    }

    public function provider(): string
    {
        return 'vault';
    }
}

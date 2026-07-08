<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DBConsole\Domain;

/**
 * What encryption status an engine can report, and whether it can require
 * TLS on a created account. Read-only capability declaration: DBConsole
 * displays at-rest status but never configures it.
 */
final readonly class EncryptionCapabilities
{
    public function __construct(
        public bool $canReadAtRestStatus,
        public bool $canRequireTlsOnAccount,
        public ?string $atRestMechanism = null,
    ) {}
}

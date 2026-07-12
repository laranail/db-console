<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DBConsole\Servers;

use Simtabi\Laranail\DBConsole\Enums\EngineType;

/**
 * A resolved server definition: its name, engine, admin connection key, and
 * TLS options. Built from config (static) or a DbServer catalog row
 * (dynamic); both surface through the ServerRegistry identically.
 */
final readonly class ServerDefinition
{
    /**
     * @param  array{enabled: bool, verify: bool, ca: ?string, cert: ?string, key: ?string}  $tls
     */
    public function __construct(
        public string $name,
        public EngineType $engine,
        public string $connection,
        public array $tls,
        public bool $showAtRestStatus = true,
        public bool $editable = false,
    ) {}

    public function tlsEnabled(): bool
    {
        return $this->tls['enabled'];
    }
}

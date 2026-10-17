<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DBConsole\Servers;

use Simtabi\Laranail\DBConsole\Enums\Hosting;
use Simtabi\Laranail\DBConsole\Enums\EngineType;
use Simtabi\Laranail\DBConsole\Tunnels\SshSettings;

/**
 * A resolved server definition: its name, engine, admin connection key, TLS
 * options, hosting, and the SSH tunnel its admin connection goes through
 * (null when it connects directly). Built from config (static) or a DbServer catalog row
 * (dynamic); both surface through the ServerRegistry identically.
 */
final readonly class ServerDefinition
{
    /**
     * @param array{enabled: bool, verify: bool, ca: ?string, cert: ?string, key: ?string} $tls
     */
    public function __construct(
        public string $name,
        public EngineType $engine,
        public string $connection,
        public array $tls,
        public bool $showAtRestStatus = true,
        public bool $editable = false,
        public Hosting $hosting = Hosting::SelfHosted,
        public ?SshSettings $ssh = null,
    ) {}

    public function tunnelled(): bool
    {
        return $this->ssh instanceof SshSettings;
    }

    public function tlsEnabled(): bool
    {
        return $this->tls['enabled'];
    }
}

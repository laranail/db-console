<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DBConsole\Encryption;

use Simtabi\Laranail\DBConsole\Enums\Severity;
use Simtabi\Laranail\DBConsole\Enums\TlsStatus;
use Simtabi\Laranail\DBConsole\Enums\EngineType;
use Illuminate\Contracts\Config\Repository as Config;
use Simtabi\Laranail\DBConsole\Servers\ServerRegistry;

/**
 * TLS status and enforcement for admin connections (section 8, scope 3). TLS
 * is the one connection-security thing DBConsole owns, because admin auth
 * crosses it — so it is mandatory by default and doctor ERRORS (not just
 * warns) on a non-local server with TLS off.
 */
final readonly class TlsChecker
{
    private const array LOCAL_HOSTS = ['localhost', '127.0.0.1', '::1', ''];

    public function __construct(
        private Config $config,
        private ServerRegistry $registry,
    ) {}

    public function status(string $server): TlsStatus
    {
        $definition = $this->registry->definition($server);

        if ($definition->engine === EngineType::Sqlite) {
            return TlsStatus::NotApplicable;
        }

        if (! $definition->tlsEnabled()) {
            return TlsStatus::Off;
        }

        return ($definition->tls['verify'] ?? true) ? TlsStatus::RequiredOk : TlsStatus::Unverified;
    }

    public function isLocal(string $server): bool
    {
        $definition = $this->registry->definition($server);
        if ($definition->engine === EngineType::Sqlite) {
            return true;
        }

        $host = (string) $this->config->get("database.connections.{$definition->connection}.host", 'localhost');

        return in_array(strtolower($host), self::LOCAL_HOSTS, true);
    }

    /**
     * Doctor problems for a server's TLS posture. A non-local server with TLS
     * off is an ERROR; a non-local server with TLS on but unverified is a
     * WARNING. Local servers are exempt.
     *
     * @return list<array{severity: Severity, message: string}>
     */
    public function problems(string $server): array
    {
        if ($this->isLocal($server)) {
            return [];
        }

        return match ($this->status($server)) {
            TlsStatus::Off => [[
                'severity' => Severity::Error,
                'message'  => "TLS is OFF on non-local server '{$server}'. Admin credentials cross this connection; enable TLS (tls.enabled=true).",
            ]],
            TlsStatus::Unverified => [[
                'severity' => Severity::Warning,
                'message'  => "TLS on server '{$server}' does not verify the server certificate (tls.verify=false); enable verification.",
            ]],
            default => [],
        };
    }

    /**
     * Whether a non-local server would fail doctor on TLS (an error-level
     * problem exists).
     */
    public function hasBlockingProblem(string $server): bool
    {
        return array_any($this->problems($server), fn (array $problem): bool => $problem['severity'] === Severity::Error);
    }
}

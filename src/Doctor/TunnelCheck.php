<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DBConsole\Doctor;

use Simtabi\Laranail\DBConsole\Tunnels\SshSettings;
use Simtabi\Laranail\DBConsole\Tunnels\TunnelManager;
use Simtabi\Laranail\DBConsole\Servers\ServerRegistry;
use Simtabi\Laranail\DBConsole\Exceptions\TunnelFailed;
use Simtabi\Laranail\DBConsole\Exceptions\DBConsoleException;

/**
 * Doctor findings for a server's SSH tunnel: the driver can run here, the
 * host key is pinned, and the tunnel opens. The round trip through it is the
 * reachability check that follows, which runs over the tunnel.
 */
final readonly class TunnelCheck
{
    public function __construct(
        private ServerRegistry $registry,
        private TunnelManager $tunnels,
    ) {}

    /**
     * Empty for a server without a tunnel. Any error-level finding means the
     * server cannot be reached, so doctor stops there for this server.
     *
     * @return list<DoctorFinding>
     */
    public function findings(string $server): array
    {
        try {
            $definition = $this->registry->definition($server);
        } catch (DBConsoleException) {
            // A malformed ssh block (no pin, two keys) surfaces from the
            // reachability check with the same message; nothing to add here.
            return [];
        }

        $ssh = $definition->ssh;
        if (! $ssh instanceof SshSettings) {
            return [];
        }

        $reason = $this->tunnels->driver($ssh->driver)->unavailableReason($ssh);
        if ($reason !== null) {
            return [DoctorFinding::error("server:{$server}:ssh-driver", "The {$ssh->driver->value} tunnel driver cannot run: {$reason}.")];
        }

        $findings = [
            DoctorFinding::ok("server:{$server}:ssh-driver", "Tunnel driver for '{$server}': {$ssh->driver->value}."),
            DoctorFinding::ok(
                "server:{$server}:ssh-host-key",
                "Host key for {$ssh->host}:{$ssh->port} is pinned ({$ssh->hostKey->type} {$ssh->hostKey->fingerprint()}).",
            ),
        ];

        try {
            $this->registry->resolve($server);
            $target = $this->registry->tunnelTarget($server);
        } catch (TunnelFailed $e) {
            return [...$findings, DoctorFinding::error("server:{$server}:ssh-tunnel", $e->userMessage())];
        } catch (DBConsoleException) {
            return $findings;
        }

        $findings[] = DoctorFinding::ok(
            "server:{$server}:ssh-tunnel",
            "SSH tunnel for '{$server}' is up: {$ssh->user}@{$ssh->host}:{$ssh->port} to " . ($target?->describe() ?? 'its target') . '.',
        );

        return $findings;
    }
}

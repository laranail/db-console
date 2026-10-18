<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DBConsole\Tunnels\Drivers;

use RuntimeException;
use phpseclib4\Net\SSH2;
use Simtabi\Laranail\DBConsole\Tunnels\TunnelSpec;
use Simtabi\Laranail\DBConsole\Tunnels\SshSettings;
use Simtabi\Laranail\DBConsole\Enums\TunnelDriverType;
use Simtabi\Laranail\DBConsole\Exceptions\TunnelFailed;
use Simtabi\Laranail\DBConsole\Tunnels\Contracts\Tunnel;
use Simtabi\Laranail\DBConsole\Tunnels\Support\WorkerProcess;
use Simtabi\Laranail\DBConsole\Tunnels\Contracts\TunnelDriver;

/**
 * Tunnels in pure PHP with phpseclib, for hosts with no ssh binary. The
 * forwarding runs in a child PHP process (ForwardPump), because the request
 * that uses the tunnel is blocked inside PDO and cannot move bytes itself.
 *
 * The child gets its settings, private key included, on stdin, verifies the
 * pinned host key before it authenticates, and exits when its stdin closes,
 * which happens when this process closes the tunnel or dies.
 */
final readonly class PhpseclibTunnelDriver implements TunnelDriver
{
    public static function script(): string
    {
        return dirname(__DIR__, 3) . '/resources/bin/phpseclib-tunnel.php';
    }

    public function type(): TunnelDriverType
    {
        return TunnelDriverType::Phpseclib;
    }

    public function unavailableReason(SshSettings $ssh): ?string
    {
        if (! class_exists(SSH2::class)) {
            return 'phpseclib is not installed: composer require phpseclib/phpseclib:^4.0.1';
        }

        return WorkerProcess::php() === null ? 'no PHP CLI binary was found to run the tunnel process' : null;
    }

    public function open(TunnelSpec $spec): Tunnel
    {
        $reason = $this->unavailableReason($spec->ssh);
        if ($reason !== null) {
            throw TunnelFailed::forServer($spec->server, $reason);
        }

        try {
            $worker = WorkerProcess::start(self::script(), $this->settings($spec), $spec->ssh->connectTimeout + 10, 'phpseclib tunnel');
        } catch (RuntimeException $e) {
            $spec->directory->remove();

            throw TunnelFailed::forServer($spec->server, $e->getMessage(), $e);
        }

        return new PhpseclibTunnel($worker->process, $worker->input, $worker->local, $spec->directory);
    }

    /**
     * @return array<string, mixed>
     */
    private function settings(TunnelSpec $spec): array
    {
        $remote = $spec->remote;

        return [
            'host'            => $spec->ssh->host,
            'port'            => $spec->ssh->port,
            'user'            => $spec->ssh->user,
            'host_key'        => "{$spec->ssh->hostKey->type} {$spec->ssh->hostKey->key}",
            'private_key'     => (string) file_get_contents($spec->privateKeyPath),
            'remote'          => $remote->isSocket() ? ['socket' => $remote->socket] : ['host' => $remote->host, 'port' => $remote->port],
            'listen'          => $spec->localSocket !== null ? ['socket' => $spec->localSocket] : [],
            'connect_timeout' => $spec->ssh->connectTimeout,
            'keepalive'       => $spec->ssh->keepAlive,
        ];
    }
}

<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DBConsole\Tunnels\Drivers;

use Throwable;
use RuntimeException;
use Symfony\Component\Process\Process;
use Simtabi\Laranail\DBConsole\Tunnels\Endpoint;
use Simtabi\Laranail\DBConsole\Tunnels\TunnelSpec;
use Simtabi\Laranail\DBConsole\Tunnels\SshSettings;
use Simtabi\Laranail\DBConsole\Enums\TunnelDriverType;
use Simtabi\Laranail\DBConsole\Exceptions\TunnelFailed;
use Simtabi\Laranail\DBConsole\Tunnels\Contracts\Tunnel;
use Simtabi\Laranail\DBConsole\Tunnels\Contracts\TunnelDriver;

/**
 * Tunnels with the OpenSSH client: `ssh -N -L <local>:<remote>`. The host key
 * is pinned through a one-line known_hosts the driver writes, with
 * StrictHostKeyChecking=yes, so an unknown or changed key is refused before
 * any credential is offered. The user's own ~/.ssh/config, agent and
 * known_hosts are ignored, so what runs is exactly what the server's config
 * says and nothing on the host can widen it.
 */
final readonly class OpenSshTunnelDriver implements TunnelDriver
{
    /** Attempts when another process takes the chosen port first. */
    private const int PORT_ATTEMPTS = 3;

    public function type(): TunnelDriverType
    {
        return TunnelDriverType::OpenSsh;
    }

    public function unavailableReason(SshSettings $ssh): ?string
    {
        try {
            // `ssh -V` prints its version to stderr and exits 0.
            $process = new Process([$ssh->binary, '-V']);
            $process->run();
        } catch (Throwable $e) {
            return "the ssh binary '{$ssh->binary}' could not be run: {$e->getMessage()}";
        }

        return $process->isSuccessful()
            ? null
            : "the ssh binary '{$ssh->binary}' is not available (exit {$process->getExitCode()})";
    }

    public function open(TunnelSpec $spec): Tunnel
    {
        $knownHosts = $spec->directory->write(
            'known_hosts',
            $spec->ssh->hostKey->knownHostsLine($spec->ssh->host, $spec->ssh->port) . "\n",
        );

        $attempts = $spec->localSocket === null ? self::PORT_ATTEMPTS : 1;
        $reason = 'the forwarder did not start';

        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            try {
                $local = $spec->localSocket !== null
                    ? Endpoint::socket($spec->localSocket)
                    : Endpoint::tcp('127.0.0.1', $this->freePort());
            } catch (RuntimeException $e) {
                $reason = $e->getMessage();

                break;
            }

            $process = new Process($this->command($spec, $knownHosts, $local));
            $process->setTimeout(null);

            try {
                $process->start();
            } catch (Throwable $e) {
                $reason = "ssh could not be started: {$e->getMessage()}";

                break;
            }

            $reason = $this->awaitReady($process, $local, $spec->ssh->connectTimeout + 5);
            if ($reason === null) {
                return new OpenSshTunnel($process, $local, $spec->directory);
            }

            $process->stop(1);

            // Only a port lost to a race is worth another attempt.
            if (! str_contains($reason, 'Address already in use') && ! str_contains($reason, 'cannot listen')) {
                break;
            }
        }

        $spec->directory->remove();

        throw TunnelFailed::forServer($spec->server, $reason);
    }

    /**
     * The full argv. Public so the hardening can be asserted without running
     * ssh.
     *
     * @return list<string>
     */
    public function command(TunnelSpec $spec, string $knownHosts, Endpoint $local): array
    {
        $ssh = $spec->ssh;
        $null = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';

        $options = [
            'BatchMode'                    => 'yes',
            'StrictHostKeyChecking'        => 'yes',
            'UserKnownHostsFile'           => $knownHosts,
            'GlobalKnownHostsFile'         => $null,
            'UpdateHostKeys'               => 'no',
            'CheckHostIP'                  => 'no',
            'IdentitiesOnly'               => 'yes',
            'IdentityFile'                 => $spec->privateKeyPath,
            'IdentityAgent'                => 'none',
            'PasswordAuthentication'       => 'no',
            'KbdInteractiveAuthentication' => 'no',
            'ExitOnForwardFailure'         => 'yes',
            'ConnectTimeout'               => (string) $ssh->connectTimeout,
            'ServerAliveInterval'          => (string) max(1, $ssh->keepAlive),
            'ServerAliveCountMax'          => '3',
            'StreamLocalBindMask'          => '0177',
            'StreamLocalBindUnlink'        => 'yes',
            'LogLevel'                     => 'ERROR',
        ];

        $command = [$ssh->binary, '-N', '-F', $null];
        foreach ($options as $key => $value) {
            $command[] = '-o';
            $command[] = "{$key}={$value}";
        }

        return [
            ...$command,
            '-p', (string) $ssh->port,
            '-l', $ssh->user,
            '-L', $local->forwardSpec() . ':' . $spec->remote->forwardSpec(),
            '--', $ssh->host,
        ];
    }

    /**
     * Wait until the local end accepts a connection, the process exits, or
     * the deadline passes. Null when ready, otherwise the reason it is not.
     */
    private function awaitReady(Process $process, Endpoint $local, int $seconds): ?string
    {
        $deadline = microtime(true) + $seconds;

        while (microtime(true) < $deadline) {
            if (! $process->isRunning()) {
                return $this->diagnose($process);
            }

            if ($this->accepts($local)) {
                return null;
            }

            usleep(50_000);
        }

        return "ssh did not open the forward within {$seconds}s";
    }

    /**
     * ssh binds the local end only after it has verified the host key,
     * authenticated and set up the forward (ExitOnForwardFailure), so a
     * listener there means the tunnel is ready.
     */
    private function accepts(Endpoint $local): bool
    {
        if ($local->isSocket()) {
            return @filetype((string) $local->socket) === 'socket';
        }

        $connection = @stream_socket_client("tcp://{$local->forwardSpec()}", $errno, $error, 0.2);
        if ($connection === false) {
            return false;
        }

        fclose($connection);

        return true;
    }

    private function diagnose(Process $process): string
    {
        $stderr = trim($process->getErrorOutput());

        return $stderr !== ''
            ? (string) preg_replace('/\s+/', ' ', $stderr)
            : "ssh exited with status {$process->getExitCode()}";
    }

    private function freePort(): int
    {
        $socket = @stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
        if ($socket === false) {
            throw new RuntimeException("could not find a free loopback port: {$error}");
        }

        $name = (string) stream_socket_get_name($socket, false);
        fclose($socket);

        return (int) substr($name, strrpos($name, ':') + 1);
    }
}

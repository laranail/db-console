<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DBConsole\Tunnels;

use Throwable;
use RuntimeException;
use Illuminate\Contracts\Container\Container;
use Simtabi\Laranail\DBConsole\Secrets\SecretVault;
use Simtabi\Laranail\DBConsole\Enums\TunnelDriverType;
use Simtabi\Laranail\DBConsole\Exceptions\TunnelFailed;
use Simtabi\Laranail\DBConsole\Servers\ServerDefinition;
use Simtabi\Laranail\DBConsole\Tunnels\Contracts\Tunnel;
use Simtabi\Laranail\DBConsole\Tunnels\Relay\VerifyingRelay;
use Simtabi\Laranail\DBConsole\Tunnels\Contracts\TunnelDriver;
use Simtabi\Laranail\DBConsole\Tunnels\Contracts\TlsRelayLauncher;
use Simtabi\Laranail\DBConsole\Tunnels\Drivers\OpenSshTunnelDriver;
use Simtabi\Laranail\DBConsole\Tunnels\Drivers\PhpseclibTunnelDriver;

/**
 * Opens and keeps one tunnel per server for the life of the process, reopens
 * one that died, and closes them all when the application terminates (and at
 * shutdown, for anything that never reaches terminate).
 */
final class TunnelManager
{
    /** @var array<string, Tunnel> */
    private array $tunnels = [];

    private bool $shutdownRegistered = false;

    public function __construct(private readonly Container $container) {}

    /**
     * The open tunnel for a server, opening (or reopening) it when needed.
     *
     * @throws TunnelFailed
     */
    public function open(ServerDefinition $definition, Endpoint $remote, ?string $engineDriver): Tunnel
    {
        $existing = $this->tunnels[$definition->name] ?? null;
        if ($existing?->isAlive() === true) {
            return $existing;
        }

        $existing?->close();
        unset($this->tunnels[$definition->name]);

        $ssh = $definition->ssh ?? throw new RuntimeException("server '{$definition->name}' has no ssh block");
        $spec = $this->spec($definition->name, $ssh, $remote, $engineDriver);

        $this->registerShutdown();

        $tunnel = $this->driver($ssh->driver)->open($spec);

        if (VerifyingRelay::appliesTo($definition, $remote, $engineDriver)) {
            try {
                $tunnel = $this->container->make(TlsRelayLauncher::class)->launch($definition, $tunnel, $remote, $spec->directory, $ssh->connectTimeout);
            } catch (RuntimeException $e) {
                $tunnel->close();

                throw TunnelFailed::forServer($definition->name, "the MySQL TLS relay did not start: {$e->getMessage()}", $e);
            }
        }

        return $this->tunnels[$definition->name] = $tunnel;
    }

    public function isAlive(string $server): bool
    {
        return isset($this->tunnels[$server]) && $this->tunnels[$server]->isAlive();
    }

    public function close(string $server): void
    {
        $this->tunnels[$server]?->close();
        unset($this->tunnels[$server]);
    }

    public function closeAll(): void
    {
        foreach (array_keys($this->tunnels) as $server) {
            $this->close($server);
        }
    }

    public function driver(TunnelDriverType $type): TunnelDriver
    {
        return match ($type) {
            TunnelDriverType::OpenSsh   => $this->container->make(OpenSshTunnelDriver::class),
            TunnelDriverType::Phpseclib => $this->container->make(PhpseclibTunnelDriver::class),
        };
    }

    /**
     * Everything the driver needs, with the private key on disk. A key from
     * the secrets driver is written into the tunnel's 0700 directory as a
     * 0600 file, and goes when the tunnel closes.
     *
     * @throws TunnelFailed
     */
    public function spec(string $server, SshSettings $ssh, Endpoint $remote, ?string $engineDriver): TunnelSpec
    {
        try {
            $directory = PrivateDirectory::create($ssh->socketDirectory);
        } catch (RuntimeException $e) {
            throw TunnelFailed::forServer($server, $e->getMessage(), $e);
        }

        try {
            $keyPath = $ssh->keySecret !== null
                ? $directory->write('id_tunnel', $this->secretKey($ssh->keySecret))
                : $this->readableKey((string) $ssh->keyPath);
        } catch (Throwable $e) {
            $directory->remove();

            throw $e instanceof TunnelFailed ? $e : TunnelFailed::forServer($server, $e->getMessage(), $e);
        }

        $localSocket = null;
        if ($ssh->local === LocalEnd::Socket) {
            $localSocket = $directory->file(TunnelledConnection::socketName($engineDriver));

            // sun_path is 104 bytes on macOS and 108 on Linux, NUL included.
            if (strlen($localSocket) > 100) {
                $directory->remove();

                throw TunnelFailed::forServer(
                    $server,
                    "the local socket path '{$localSocket}' is too long for a Unix socket; set ssh.socket_directory to a shorter directory",
                );
            }
        }

        return new TunnelSpec($server, $ssh, $remote, $keyPath, $directory, $localSocket);
    }

    private function secretKey(string $reference): string
    {
        $key = $this->container->make(SecretVault::class)->reveal($reference)->reveal();

        // OpenSSH refuses a key file without its final newline.
        return rtrim($key, "\r\n") . "\n";
    }

    private function readableKey(string $path): string
    {
        if (! is_file($path) || ! is_readable($path)) {
            throw new RuntimeException("the private key '{$path}' (ssh.key) is not a readable file");
        }

        return $path;
    }

    private function registerShutdown(): void
    {
        if ($this->shutdownRegistered) {
            return;
        }

        $this->shutdownRegistered = true;
        register_shutdown_function($this->closeAll(...));
    }
}

<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DBConsole\Tunnels;

use InvalidArgumentException;
use Simtabi\Laranail\DBConsole\Enums\TunnelDriverType;

/**
 * A server's `ssh` block, validated. Built only when the block is enabled, so
 * a server without a tunnel carries none of this.
 *
 * The remote target defaults to the admin connection's own host and port as
 * the SSH server sees them; `remote_host`/`remote_port` override that, and
 * `remote_socket` forwards to a Unix socket on the SSH host instead.
 */
final readonly class SshSettings
{
    public function __construct(
        public TunnelDriverType $driver,
        public string $host,
        public int $port,
        public string $user,
        public HostKey $hostKey,
        public ?string $keyPath,
        public ?string $keySecret,
        public ?string $remoteHost,
        public ?int $remotePort,
        public ?string $remoteSocket,
        public LocalEnd $local,
        public string $socketDirectory,
        public int $connectTimeout,
        public int $keepAlive,
        public string $binary,
    ) {}

    /**
     * @param array<string, mixed> $raw
     *
     * @throws InvalidArgumentException naming the first problem found
     */
    public static function fromArray(array $raw): self
    {
        $driverName = (string) ($raw['driver'] ?? TunnelDriverType::OpenSsh->value);
        $driver = TunnelDriverType::tryFrom($driverName)
            ?? throw new InvalidArgumentException(
                "ssh.driver '{$driverName}' is not one of: " . implode(', ', TunnelDriverType::values()),
            );

        $host = self::string($raw['host'] ?? null) ?? throw new InvalidArgumentException('ssh.host is required');
        $user = self::string($raw['user'] ?? null) ?? throw new InvalidArgumentException('ssh.user is required');

        $keyPath = self::string($raw['key'] ?? null);
        $keySecret = self::string($raw['key_secret'] ?? null);
        if (($keyPath === null) === ($keySecret === null)) {
            throw new InvalidArgumentException(
                'set exactly one of ssh.key (a private key path) and ssh.key_secret (a secrets-driver reference)',
            );
        }

        $localName = (string) ($raw['local'] ?? LocalEnd::Port->value);
        $local = LocalEnd::tryFrom($localName)
            ?? throw new InvalidArgumentException("ssh.local must be 'port' or 'socket', got '{$localName}'");

        $remoteSocket = self::string($raw['remote_socket'] ?? null);
        if ($remoteSocket !== null && ! str_starts_with($remoteSocket, '/')) {
            throw new InvalidArgumentException('ssh.remote_socket must be an absolute path');
        }

        return new self(
            driver: $driver,
            host: $host,
            port: self::port($raw['port'] ?? 22, 'ssh.port'),
            user: $user,
            hostKey: HostKey::parse(self::string($raw['host_key'] ?? null)),
            keyPath: $keyPath,
            keySecret: $keySecret,
            remoteHost: self::string($raw['remote_host'] ?? null),
            remotePort: isset($raw['remote_port']) && $raw['remote_port'] !== '' ? self::port($raw['remote_port'], 'ssh.remote_port') : null,
            remoteSocket: $remoteSocket,
            local: $local,
            socketDirectory: self::string($raw['socket_directory'] ?? null) ?? sys_get_temp_dir(),
            connectTimeout: max(1, (int) ($raw['connect_timeout'] ?? 10)),
            keepAlive: max(0, (int) ($raw['keepalive'] ?? 15)),
            binary: self::string($raw['binary'] ?? null) ?? 'ssh',
        );
    }

    /**
     * Where the SSH server connects onward to. Without an override, the admin
     * connection's own host and port.
     */
    public function remote(?string $connectionHost, ?int $connectionPort, int $defaultPort): Endpoint
    {
        if ($this->remoteSocket !== null) {
            return Endpoint::socket($this->remoteSocket);
        }

        $host = $this->remoteHost ?? $connectionHost;
        if ($host === null || $host === '' || str_starts_with($host, '/')) {
            throw new InvalidArgumentException(
                'the tunnel has no remote target: set ssh.remote_host (or ssh.remote_socket), or a TCP host on the admin connection',
            );
        }

        return Endpoint::tcp($host, $this->remotePort ?? $connectionPort ?? $defaultPort);
    }

    private static function string(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    private static function port(mixed $value, string $key): int
    {
        $port = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 65535]]);

        return is_int($port) ? $port : throw new InvalidArgumentException("{$key} must be a port number (1-65535)");
    }
}

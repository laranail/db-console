<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DBConsole\Tunnels;

use InvalidArgumentException;

/**
 * One end of a tunnel: a TCP host and port, or a Unix socket path. Used for
 * both the local end PDO connects to and the remote target the SSH server
 * connects onward to.
 */
final readonly class Endpoint
{
    private const array LOOPBACK = ['localhost', '127.0.0.1', '::1'];

    private function __construct(
        public ?string $host,
        public ?int $port,
        public ?string $socket,
    ) {}

    public static function tcp(string $host, int $port): self
    {
        if ($host === '' || $port < 1 || $port > 65535) {
            throw new InvalidArgumentException("not a TCP endpoint: '{$host}:{$port}'");
        }

        return new self($host, $port, null);
    }

    public static function socket(string $path): self
    {
        if (! str_starts_with($path, '/')) {
            throw new InvalidArgumentException("a socket path must be absolute, got '{$path}'");
        }

        return new self(null, null, $path);
    }

    public function isSocket(): bool
    {
        return $this->socket !== null;
    }

    /**
     * A socket, or a loopback address: either way the hop from wherever this
     * endpoint is resolved never crosses a network.
     */
    public function isLocalToItsHost(): bool
    {
        return $this->isSocket() || in_array(strtolower((string) $this->host), self::LOOPBACK, true);
    }

    /**
     * The form OpenSSH's -L takes for this end: `host:port` (an IPv6 host in
     * brackets) or the socket path.
     */
    public function forwardSpec(): string
    {
        if ($this->socket !== null) {
            return $this->socket;
        }

        $host = str_contains((string) $this->host, ':') ? "[{$this->host}]" : (string) $this->host;

        return "{$host}:{$this->port}";
    }

    public function describe(): string
    {
        return $this->socket !== null ? "unix:{$this->socket}" : $this->forwardSpec();
    }
}

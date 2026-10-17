<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DBConsole\Tunnels;

use InvalidArgumentException;

/**
 * The SSH server's pinned public host key. Required: there is no
 * trust-on-first-use, because the first use is exactly when a
 * man-in-the-middle would be trusted.
 *
 * Takes the public key line as `ssh-keyscan` or the server's
 * /etc/ssh/ssh_host_*_key.pub prints it (`type base64 [comment]`), or a
 * known_hosts line (`host type base64`). Only the type and key are kept.
 */
final readonly class HostKey
{
    private const array TYPES = [
        'ssh-ed25519',
        'ecdsa-sha2-nistp256',
        'ecdsa-sha2-nistp384',
        'ecdsa-sha2-nistp521',
        'ssh-rsa',
        'sk-ssh-ed25519@openssh.com',
        'sk-ecdsa-sha2-nistp256@openssh.com',
    ];

    private function __construct(
        public string $type,
        public string $key,
    ) {}

    public static function parse(?string $line): self
    {
        $fields = preg_split('/\s+/', trim((string) $line)) ?: [];

        // Find the type field; a known_hosts line has the host pattern first.
        foreach ($fields as $index => $field) {
            if (in_array($field, self::TYPES, true)) {
                return self::fromParts($field, (string) ($fields[$index + 1] ?? ''));
            }
        }

        throw new InvalidArgumentException(
            trim((string) $line) === ''
                ? 'no host key is pinned (ssh.host_key); DBConsole never trusts a host key on first use'
                : 'ssh.host_key is not a public key line (expected e.g. "ssh-ed25519 AAAA...")',
        );
    }

    /**
     * The SHA256 fingerprint OpenSSH prints (`SHA256:...`, unpadded base64).
     */
    public function fingerprint(): string
    {
        return 'SHA256:' . rtrim(base64_encode(hash('sha256', (string) base64_decode($this->key, true), true)), '=');
    }

    /**
     * The one known_hosts line pinning this key to the server. A non-default
     * port uses OpenSSH's `[host]:port` form, or the pin would not match.
     */
    public function knownHostsLine(string $host, int $port): string
    {
        $pattern = $port === 22 ? $host : "[{$host}]:{$port}";

        return "{$pattern} {$this->type} {$this->key}";
    }

    /**
     * Whether a key the server presented (`type base64`, as phpseclib's
     * getServerPublicHostKey() returns it) is this one.
     */
    public function matches(?string $presented): bool
    {
        if ($presented === null) {
            return false;
        }

        try {
            $other = self::parse($presented);
        } catch (InvalidArgumentException) {
            return false;
        }

        return $other->type === $this->type && hash_equals($this->key, $other->key);
    }

    private static function fromParts(string $type, string $key): self
    {
        $decoded = base64_decode($key, true);

        // The blob starts with the length-prefixed type name, so a key pasted
        // under the wrong type, or truncated, is caught here and not at the
        // first connection.
        if ($decoded === false || strlen($decoded) < 4) {
            throw new InvalidArgumentException('ssh.host_key does not contain a base64 public key');
        }

        $length = unpack('N', substr($decoded, 0, 4))[1] ?? 0;
        if (! is_int($length) || substr($decoded, 4, $length) !== $type) {
            throw new InvalidArgumentException("ssh.host_key's key data is not a {$type} key");
        }

        return new self($type, $key);
    }
}

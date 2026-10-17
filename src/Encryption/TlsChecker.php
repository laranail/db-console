<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DBConsole\Encryption;

use Throwable;
use Simtabi\Laranail\DBConsole\Enums\Severity;
use Illuminate\Database\ConfigurationUrlParser;
use Simtabi\Laranail\DBConsole\Enums\TlsStatus;
use Simtabi\Laranail\DBConsole\Enums\EngineType;
use Illuminate\Contracts\Config\Repository as Config;
use Simtabi\Laranail\DBConsole\Servers\ServerRegistry;

/**
 * TLS status and enforcement for admin connections (section 8, scope 3). TLS
 * is the one connection-security thing DBConsole owns, because admin auth
 * crosses it — so it is mandatory by default and doctor ERRORS (not just
 * warns) on a non-local server with TLS off.
 *
 * The status is measured from the live session through a TlsProbe. It used to
 * be read back from config, so `tls.enabled=true` reported "required and
 * verified" for a session that was plaintext. Config still decides two
 * things the session cannot report: whether TLS is required, and whether the
 * server certificate is verified.
 */
final readonly class TlsChecker
{
    private const array LOCAL_HOSTS = ['localhost', '127.0.0.1', '::1', ''];

    public function __construct(
        private Config $config,
        private ServerRegistry $registry,
        private TlsProbe $probe,
    ) {}

    public function status(string $server): TlsStatus
    {
        $definition = $this->registry->definition($server);

        if ($definition->engine === EngineType::Sqlite) {
            return TlsStatus::NotApplicable;
        }

        $encrypted = $this->measure($server);

        if ($encrypted === null) {
            return $definition->tlsEnabled() ? TlsStatus::Unmeasured : TlsStatus::Off;
        }

        if (! $encrypted) {
            return TlsStatus::Off;
        }

        // Encrypted, but only a TLS block that is enabled and verifying means
        // the certificate was checked: anything else was negotiated without it.
        return $definition->tlsEnabled() && ($definition->tls['verify'] ?? true)
            ? TlsStatus::RequiredOk
            : TlsStatus::Unverified;
    }

    /**
     * Ask the live admin session whether it is encrypted: true, false, or
     * null when it cannot be asked (unreachable, misconfigured, SQLite, or a
     * driver with no way to tell).
     */
    public function measure(string $server): ?bool
    {
        try {
            return $this->probe->isEncrypted($this->registry->connection($server)->underlying());
        } catch (Throwable) {
            return null;
        }
    }

    public function isLocal(string $server): bool
    {
        $definition = $this->registry->definition($server);
        if ($definition->engine === EngineType::Sqlite) {
            return true;
        }

        $hosts = $this->hosts($definition->connection);

        // A connection with no host at all (a socket) is local; one with
        // several (read/write split) is local only if every host is.
        return array_all($hosts, static fn (string $host): bool => in_array(strtolower($host), self::LOCAL_HOSTS, true));
    }

    /**
     * Doctor problems for a server's TLS posture. A non-local server whose
     * session is plaintext is an ERROR; one encrypted without verification,
     * or configured but unmeasurable, is a WARNING. Local servers are exempt.
     *
     * @return list<array{severity: Severity, message: string}>
     */
    public function problems(string $server): array
    {
        if ($this->isLocal($server)) {
            return [];
        }

        $definition = $this->registry->definition($server);

        return match ($this->status($server)) {
            TlsStatus::Off => [[
                'severity' => Severity::Error,
                'message'  => $definition->tlsEnabled()
                    ? "TLS is configured on server '{$server}' (tls.enabled=true) but the admin session is plaintext. "
                        . 'Admin credentials cross this connection.' . $this->plaintextHint($definition->engine)
                    : "TLS is OFF on non-local server '{$server}'. Admin credentials cross this connection; enable TLS (tls.enabled=true).",
            ]],
            TlsStatus::Unverified => [[
                'severity' => Severity::Warning,
                'message'  => $definition->tlsEnabled()
                    ? "TLS on server '{$server}' does not verify the server certificate (tls.verify=false); enable verification."
                    : "The session to server '{$server}' is encrypted, but DBConsole does not require it (tls.enabled=false), so nothing stops it falling back to plaintext; enable TLS.",
            ]],
            TlsStatus::Unmeasured => [[
                'severity' => Severity::Warning,
                'message'  => "TLS is configured on server '{$server}' but could not be measured on the live session, so whether it is in effect is unknown.",
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

    /**
     * Every host a connection can reach, including one given only as a
     * `url` (DATABASE_URL) and the read/write split hosts. Reading `host`
     * alone treated a url-only connection as localhost, which exempted
     * exactly the managed-database setups from the TLS check.
     *
     * @return list<string>
     */
    private function hosts(string $connection): array
    {
        /** @var array<string, mixed> $config */
        $config = (array) $this->config->get("database.connections.{$connection}", []);
        $config = new ConfigurationUrlParser()->parseConfiguration($config);

        $hosts = [];
        foreach ([$config, (array) ($config['read'] ?? []), (array) ($config['write'] ?? [])] as $section) {
            foreach ((array) ($section['host'] ?? []) as $host) {
                $hosts[] = (string) $host;
            }
        }

        return $hosts === [] ? ['localhost'] : $hosts;
    }

    private function plaintextHint(EngineType $engine): string
    {
        return in_array($engine, [EngineType::Mysql, EngineType::Mariadb], true)
            ? ' pdo_mysql starts TLS only when tls.ca (or tls.cert/tls.key) is set; set tls.ca to the provider\'s CA certificate.'
            : '';
    }
}

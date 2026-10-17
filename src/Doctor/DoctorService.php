<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DBConsole\Doctor;

use Illuminate\Database\Connection;
use Illuminate\Contracts\Events\Dispatcher;
use Simtabi\Laranail\DBConsole\Encryption\TlsChecker;
use Simtabi\Laranail\DBConsole\Servers\ServerRegistry;
use Simtabi\Laranail\DBConsole\Events\SuspiciousActivity;
use Simtabi\Laranail\DBConsole\Secrets\SecretVaultManager;
use Simtabi\Laranail\DBConsole\Encryption\SqlCipherManager;
use Simtabi\Laranail\DBConsole\Engines\AdminInspectingEngine;
use Simtabi\Laranail\DBConsole\Exceptions\DBConsoleException;

/**
 * Probes the deployment for security and health problems (scenario A). For
 * each registered server: reachability, TLS posture, the real capability set,
 * and — the doctrine-not-option check — whether the admin account is
 * root-like, in which case doctor FAILS with the exact minimal-grant fix so
 * setup won't quietly proceed insecure. Also reports the secret driver and
 * catalog encryption mode.
 */
final readonly class DoctorService
{
    public function __construct(
        private ServerRegistry $registry,
        private TlsChecker $tls,
        private SecretVaultManager $secrets,
        private SqlCipherManager $sqlcipher,
        private Dispatcher $events,
        private PostgresAdminCheck $postgresAdmin,
    ) {}

    /**
     * @return list<DoctorFinding>
     */
    public function run(): array
    {
        $findings = [];

        foreach ($this->registry->names() as $server) {
            $findings = [...$findings, ...$this->checkServer($server)];
        }

        $findings[] = $this->checkSecretDriver();
        $findings[] = $this->checkCatalogEncryption();

        return $findings;
    }

    /**
     * @return list<DoctorFinding>
     */
    public function checkServer(string $server): array
    {
        $findings = [];

        try {
            $this->registry->ensureReachable($server);
            $findings[] = DoctorFinding::ok("server:{$server}:reachable", "Server '{$server}' is reachable.");
        } catch (DBConsoleException $e) {
            return [DoctorFinding::error("server:{$server}:reachable", $e->userMessage())];
        }

        foreach ($this->tls->problems($server) as $problem) {
            $findings[] = new DoctorFinding("server:{$server}:tls", $problem['severity'], $problem['message']);
        }
        if ($this->tls->problems($server) === []) {
            $findings[] = DoctorFinding::ok("server:{$server}:tls", "TLS on '{$server}': " . $this->tls->status($server)->value . '.');
        }

        $findings = [...$findings, ...$this->checkAdminPrivileges($server)];

        $capabilities = $this->registry->engine($server)->capabilities();
        $findings[] = DoctorFinding::ok(
            "server:{$server}:capabilities",
            "Capabilities on '{$server}': " . $this->summariseCapabilities($capabilities->toArray()),
        );

        return $findings;
    }

    /**
     * Raise a SuspiciousActivity alert for each root-like admin found (a
     * security warning per section 10).
     *
     * @param list<DoctorFinding> $findings
     */
    public function alertOnSecurityFindings(array $findings): void
    {
        foreach ($findings as $finding) {
            if ($finding->isError() && str_ends_with($finding->check, ':admin')) {
                $this->events->dispatch(new SuspiciousActivity('global', $finding->message));
            }
        }
    }

    /**
     * @return list<DoctorFinding>
     */
    private function checkAdminPrivileges(string $server): array
    {
        $engine = $this->registry->engine($server);

        if ($engine instanceof AdminInspectingEngine) {
            return $this->inspectAdmin($server, $engine);
        }

        // MySQL-family exposes SHOW GRANTS for the current admin.
        if (! $engine->type()->isMysqlFamily()) {
            return [DoctorFinding::ok("server:{$server}:admin", "Admin privilege check skipped for {$engine->type()->value}.")];
        }

        try {
            $rows = $this->registry->connection($server)->select('SHOW GRANTS FOR CURRENT_USER()', ['operation' => 'server.view']);
        } catch (DBConsoleException $e) {
            return [DoctorFinding::warning("server:{$server}:admin", "Could not read admin grants: {$e->userMessage()}")];
        }

        $grants = strtoupper(implode("\n", array_map(
            static fn (array $row): string => (string) reset($row),
            $rows,
        )));

        if ($this->looksRootLike($grants)) {
            return [DoctorFinding::error(
                "server:{$server}:admin",
                "The admin account on '{$server}' is ROOT-LIKE (ALL PRIVILEGES or SUPER). Point DBConsole at a minimal admin instead.",
                $this->minimalGrantFix($server),
            )];
        }

        return [DoctorFinding::ok("server:{$server}:admin", "Admin account on '{$server}' is appropriately scoped (not root-like).")];
    }

    /**
     * @return list<DoctorFinding>
     */
    private function inspectAdmin(string $server, AdminInspectingEngine $engine): array
    {
        $connection = $this->registry->connection($server);

        try {
            $profile = [];
            foreach ($engine->adminProfile() as $statement) {
                $profile = [...$profile, ...($connection->selectStatement($statement, ['operation' => 'server.view'])[0] ?? [])];
            }
        } catch (DBConsoleException $e) {
            return [DoctorFinding::warning("server:{$server}:admin", "Could not read the admin role: {$e->userMessage()}")];
        }

        $underlying = $connection->underlying();
        $port = $underlying instanceof Connection ? $underlying->getConfig('port') : null;

        return $this->postgresAdmin->findings($server, $profile, is_numeric($port) ? (int) $port : null);
    }

    private function looksRootLike(string $upperGrants): bool
    {
        return str_contains($upperGrants, 'ALL PRIVILEGES ON *.*')
            || str_contains($upperGrants, 'SUPER')
            || str_contains($upperGrants, 'GRANT PROXY ON');
    }

    private function checkSecretDriver(): DoctorFinding
    {
        try {
            $this->secrets->assertSecureForEnvironment();
        } catch (DBConsoleException $e) {
            return DoctorFinding::error('secrets:driver', $e->userMessage());
        }

        $driver = $this->secrets->driverName();

        if ($driver->keyLivesWithCiphertext()) {
            return DoctorFinding::warning(
                'secrets:driver',
                "Secret driver is '{$driver->value}' — the key lives next to the ciphertext. Fine for a single box; use kms/vault/reference in production.",
            );
        }

        return DoctorFinding::ok('secrets:driver', "Secret driver: '{$driver->value}'.");
    }

    private function checkCatalogEncryption(): DoctorFinding
    {
        $report = $this->sqlcipher->report();

        return DoctorFinding::ok(
            'catalog:encryption',
            'Catalog encryption: ' . $report['mode'] . ($report['reason'] !== null ? " ({$report['reason']})" : ''),
        );
    }

    /**
     * @param array<string, bool|string|null> $capabilities
     */
    private function summariseCapabilities(array $capabilities): string
    {
        $enabled = [];
        foreach ($capabilities as $key => $value) {
            if ($value === true) {
                $enabled[] = $key;
            }
        }

        return implode(', ', $enabled);
    }

    private function minimalGrantFix(string $server): string
    {
        return implode("\n", MinimalAdminGrant::statements($this->registry->engine($server)));
    }
}

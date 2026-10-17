<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DBConsole\Doctor;

use Simtabi\Laranail\DBConsole\Enums\Hosting;
use Simtabi\Laranail\DBConsole\Enums\EngineType;

/**
 * Judges a server against its declared hosting, from values DoctorService
 * probes. Kept apart from DoctorService so the judgement is testable without
 * a server; the live groups run it against real ones.
 *
 * Probe keys, each optional (absent or null means it could not be asked):
 * - `mysql_user_readable` (bool): account listing and host changes read
 *   mysql.user, which a managed admin may not be granted;
 * - `sql_require_primary_key`, `gtid_mode` (string): settings a managed
 *   MySQL enforces, reported so their effects are not a surprise;
 * - `can_signal_backends` (bool): a forced Postgres drop ends other sessions,
 *   which needs pg_signal_backend.
 */
final readonly class HostingCheck
{
    /**
     * @param array<string, mixed> $probe
     *
     * @return list<DoctorFinding>
     */
    public function findings(string $server, Hosting $hosting, EngineType $engine, ?string $host, array $probe): array
    {
        $findings = [$this->hosting($server, $hosting, $engine, $host)];

        if ($engine->isMysqlFamily()) {
            $findings = [...$findings, ...$this->mysql($server, $probe)];
        }

        if ($engine === EngineType::Pgsql && is_bool($probe['can_signal_backends'] ?? null)) {
            $findings[] = $probe['can_signal_backends']
                ? DoctorFinding::ok("server:{$server}:forced-drop", "The admin on '{$server}' can end other sessions, so a forced drop succeeds.")
                : DoctorFinding::warning(
                    "server:{$server}:forced-drop",
                    "The admin on '{$server}' cannot end other roles' sessions, so dropping a database someone is connected to fails.",
                    'GRANT pg_signal_backend TO db_console_admin;  -- or close the sessions before dropping',
                );
        }

        return $findings;
    }

    private function hosting(string $server, Hosting $hosting, EngineType $engine, ?string $host): DoctorFinding
    {
        $detected = Hosting::detectFromHost($host);

        if (! $hosting->isManaged() && $detected instanceof Hosting) {
            return DoctorFinding::warning(
                "server:{$server}:hosting",
                "'{$server}' is configured as self-hosted, but its host belongs to {$detected->label()}.",
                "Set servers.{$server}.hosting = '{$detected->value}' so DBConsole hides the provider's accounts, "
                . "scopes new accounts to any host and relies on the provider's point-in-time recovery.",
            );
        }

        if (! $hosting->isManaged()) {
            return DoctorFinding::ok("server:{$server}:hosting", "'{$server}' is self-hosted.");
        }

        $profile = $hosting->profile($engine);
        $host = $profile->defaultAccountHost === null ? '' : "; new accounts default to host '{$profile->defaultAccountHost}'";

        return DoctorFinding::ok(
            "server:{$server}:hosting",
            "'{$server}' runs on {$hosting->label()}: the provider's point-in-time recovery is the primary backup{$host}.",
        );
    }

    /**
     * @param array<string, mixed> $probe
     *
     * @return list<DoctorFinding>
     */
    private function mysql(string $server, array $probe): array
    {
        $findings = [];

        if (is_bool($probe['mysql_user_readable'] ?? null)) {
            $findings[] = $probe['mysql_user_readable']
                ? DoctorFinding::ok("server:{$server}:accounts", "The admin on '{$server}' can read mysql.user.")
                : DoctorFinding::warning(
                    "server:{$server}:accounts",
                    "The admin on '{$server}' cannot read mysql.user, so listing accounts and changing an account's host fail.",
                    "GRANT SELECT ON mysql.user TO 'db_console_admin'@'%';",
                );
        }

        $settings = [];
        foreach (['sql_require_primary_key', 'gtid_mode'] as $name) {
            if (is_string($probe[$name] ?? null) && $probe[$name] !== '') {
                $settings[] = "{$name}={$probe[$name]}";
            }
        }

        if ($settings !== []) {
            $findings[] = DoctorFinding::ok(
                "server:{$server}:server-settings",
                "'{$server}' runs with " . implode(', ', $settings) . '. With sql_require_primary_key=ON every table needs a primary key.',
            );
        }

        return $findings;
    }
}

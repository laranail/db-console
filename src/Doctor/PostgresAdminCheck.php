<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DBConsole\Doctor;

/**
 * Judges a Postgres admin role from its catalog profile. Kept apart from
 * DoctorService so the judgement is testable without a server; the live
 * group runs it against real ones.
 *
 * - A superuser is root-like: it bypasses every privilege check, so a
 *   mistake made through DBConsole has no ceiling.
 * - CREATEDB and CREATEROLE are what provisioning needs; without either it
 *   fails at the first database or account.
 * - 13 is the floor: dropDatabase() sends DROP DATABASE ... WITH (FORCE).
 * - A known pooler port is a warning: a transaction-mode pooler hands each
 *   statement to whichever server session is free, which the grant
 *   transaction and scoped connections do not survive, and some poolers
 *   refuse CREATE and DROP DATABASE outright.
 */
final readonly class PostgresAdminCheck
{
    public const int MINIMUM_VERSION = 130000;

    /**
     * PgBouncer's default, Supabase's pooler, DigitalOcean's connection pools.
     *
     * @var array<int, string>
     */
    public const array POOLER_PORTS = [
        6432  => 'PgBouncer',
        6543  => 'the Supabase pooler',
        25061 => 'a DigitalOcean connection pool',
    ];

    public static function minimalRole(): string
    {
        return "CREATE ROLE db_console_admin WITH LOGIN CREATEDB CREATEROLE PASSWORD '...';";
    }

    /**
     * @param array<string, mixed> $profile the row AdminInspectingEngine::adminProfile() reads
     *
     * @return list<DoctorFinding>
     */
    public function findings(string $server, array $profile, ?int $port): array
    {
        $findings = [];

        $findings[] = $this->truthy($profile['is_superuser'] ?? false)
            ? DoctorFinding::error(
                "server:{$server}:admin",
                "The admin role on '{$server}' is a superuser, which bypasses every privilege check. Point DBConsole at a minimal admin instead.",
                self::minimalRole(),
            )
            : DoctorFinding::ok("server:{$server}:admin", "Admin role on '{$server}' is not a superuser.");

        $missing = array_keys(array_filter([
            'CREATEDB'   => ! $this->truthy($profile['can_create_db'] ?? false),
            'CREATEROLE' => ! $this->truthy($profile['can_create_role'] ?? false),
        ]));
        $findings[] = $missing === []
            ? DoctorFinding::ok("server:{$server}:admin-privileges", "Admin role on '{$server}' has CREATEDB and CREATEROLE.")
            : DoctorFinding::error(
                "server:{$server}:admin-privileges",
                "The admin role on '{$server}' lacks " . implode(', ', $missing) . ', which provisioning needs.',
                self::minimalRole(),
            );

        $version = (int) ($profile['server_version_num'] ?? 0);
        $findings[] = $version >= self::MINIMUM_VERSION
            ? DoctorFinding::ok("server:{$server}:version", "PostgreSQL on '{$server}' is {$this->version($version)}.")
            : DoctorFinding::error(
                "server:{$server}:version",
                "PostgreSQL on '{$server}' is {$this->version($version)}; DBConsole needs 13 or later (DROP DATABASE ... WITH (FORCE)).",
            );

        if ($port !== null && isset(self::POOLER_PORTS[$port])) {
            $findings[] = DoctorFinding::warning(
                "server:{$server}:pooler",
                "The admin connection for '{$server}' uses port {$port}, the port of " . self::POOLER_PORTS[$port] . '. '
                . 'A transaction-mode pooler breaks grants and drops, which run across transactions and databases.',
                'Point the admin connection at the server directly, not through the pooler.',
            );
        }

        return $findings;
    }

    /**
     * Postgres returns booleans; other drivers return 't'/'f' or 1/0.
     */
    private function truthy(mixed $value): bool
    {
        return in_array($value, [true, 1, '1', 't', 'true'], true);
    }

    private function version(int $number): string
    {
        return intdiv($number, 10000) . '.' . ($number % 10000);
    }
}

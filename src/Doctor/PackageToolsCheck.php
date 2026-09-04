<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DBConsole\Doctor;

use Throwable;
use Simtabi\Laranail\DBConsole\Enums\Severity;
use Simtabi\Laranail\Package\Tools\Services\Doctor\DoctorCheck;
use Simtabi\Laranail\Package\Tools\Services\Doctor\DoctorResult;
use Simtabi\Laranail\Package\Tools\Services\Doctor\DoctorStatus;

/**
 * Adapter that surfaces the bespoke DBConsole doctor through the standard
 * package-tools doctor (`php artisan laranail::package-tools.doctor`).
 *
 * DBConsole keeps its own rich, per-finding command
 * (`laranail::db-console.doctor`) — this adapter is purely additive: it runs
 * the same {@see DoctorService} probes (reachability, TLS, admin-privilege
 * sanity, secret driver, catalog encryption) and aggregates their findings
 * into a single pass/warn/fail row so DBConsole health also shows up in the
 * unified doctor report.
 *
 * {@see run()} resolves the bespoke DoctorService lazily and never throws, per
 * the DoctorCheck contract.
 */
final class PackageToolsCheck implements DoctorCheck
{
    /**
     * Map a bespoke severity to a package-tools doctor status: Info/Notice →
     * pass, Warning → warn, Error/Critical → fail.
     */
    public static function mapStatus(Severity $severity): DoctorStatus
    {
        return match ($severity) {
            Severity::Info, Severity::Notice    => DoctorStatus::Pass,
            Severity::Warning                   => DoctorStatus::Warn,
            Severity::Error, Severity::Critical => DoctorStatus::Fail,
        };
    }

    /**
     * Fold every bespoke finding into a single DoctorResult, keeping the worst
     * status (fail beats warn beats pass) and listing the problem findings in
     * the result detail.
     *
     * @param list<DoctorFinding> $findings
     */
    public static function aggregate(array $findings): DoctorResult
    {
        if ($findings === []) {
            return DoctorResult::skip('DBConsole doctor produced no findings (no servers registered).');
        }

        $worst = DoctorStatus::Pass;
        $problems = [];

        foreach ($findings as $finding) {
            $status = self::mapStatus($finding->severity);

            if (self::rank($status) > self::rank($worst)) {
                $worst = $status;
            }

            if ($status !== DoctorStatus::Pass) {
                $problems[] = "{$finding->check}: {$finding->message}";
            }
        }

        $ran = sprintf('%d DBConsole probe(s) ran', count($findings));
        $detail = ['findings' => count($findings), 'problems' => $problems];

        return match ($worst) {
            DoctorStatus::Fail => DoctorResult::fail(
                "{$ran}; " . count($problems) . ' blocking problem(s) found.',
                $detail,
            ),
            DoctorStatus::Warn => DoctorResult::warn(
                "{$ran}; " . count($problems) . ' warning(s) found.',
                $detail,
            ),
            default => DoctorResult::pass("{$ran}; all healthy."),
        };
    }

    public function name(): string
    {
        return 'db-console:health';
    }

    public function description(): string
    {
        return 'DBConsole servers, TLS, admin scope, secret driver and catalog encryption.';
    }

    public function run(): DoctorResult
    {
        try {
            $findings = app(DoctorService::class)->run();
        } catch (Throwable $e) {
            return DoctorResult::fail(
                'DBConsole doctor could not run: ' . $e->getMessage(),
                ['exception' => $e::class],
            );
        }

        return self::aggregate($findings);
    }

    private static function rank(DoctorStatus $status): int
    {
        return match ($status) {
            DoctorStatus::Fail                     => 2,
            DoctorStatus::Warn                     => 1,
            DoctorStatus::Pass, DoctorStatus::Skip => 0,
        };
    }
}

<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DBConsole\Doctor;

use Simtabi\Laranail\Package\Tools\Services\Doctor\DoctorCheck;

/**
 * The DBConsole doctor checks registered with the standard package-tools
 * doctor via `Package::hasDoctorChecks()`. A single aggregate adapter runs
 * the bespoke {@see DoctorService} and reports one overall health row; the
 * dedicated `laranail::db-console.doctor` command remains the detailed report.
 */
final class Checks
{
    /**
     * @return list<DoctorCheck>
     */
    public static function all(): array
    {
        return [
            new PackageToolsCheck,
        ];
    }
}

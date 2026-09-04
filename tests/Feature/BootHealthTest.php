<?php

declare(strict_types=1);

use Simtabi\Laranail\Package\Tools\Services\Boot\BootReport;

/*
 * CI health gate (failure-handling standard, rule 12): the package must boot
 * with no degraded builders. A degradable boot failure does not crash, so this
 * assertion — not a dev-only crash — is what catches a silently reduced boot in
 * CI. The report stores redacted names/criticality only (rules 11/15).
 */
it('boots with no degraded package builders', function (): void {
    $report = app(BootReport::class);

    expect($report->isHealthy())->toBeTrue(
        'package booted degraded: ' . json_encode($report->degraded()),
    );
});

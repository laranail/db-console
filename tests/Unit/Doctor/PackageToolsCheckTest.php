<?php

declare(strict_types=1);

use Simtabi\Laranail\DBConsole\Doctor\DoctorFinding;
use Simtabi\Laranail\DBConsole\Doctor\PackageToolsCheck;
use Simtabi\Laranail\DBConsole\Enums\Severity;
use Simtabi\Laranail\Package\Tools\Services\Doctor\DoctorStatus;

describe('bespoke finding → DoctorResult mapping', function (): void {
    it('maps each bespoke severity to the right doctor status', function (): void {
        expect(PackageToolsCheck::mapStatus(Severity::Info))->toBe(DoctorStatus::Pass)
            ->and(PackageToolsCheck::mapStatus(Severity::Notice))->toBe(DoctorStatus::Pass)
            ->and(PackageToolsCheck::mapStatus(Severity::Warning))->toBe(DoctorStatus::Warn)
            ->and(PackageToolsCheck::mapStatus(Severity::Error))->toBe(DoctorStatus::Fail)
            ->and(PackageToolsCheck::mapStatus(Severity::Critical))->toBe(DoctorStatus::Fail);
    });

    it('folds an error finding into a fail DoctorResult and lists the problem', function (): void {
        $result = PackageToolsCheck::aggregate([
            DoctorFinding::ok('server:main:reachable', 'Server is reachable.'),
            DoctorFinding::error('server:main:admin', 'The admin account is ROOT-LIKE.'),
        ]);

        expect($result->status)->toBe(DoctorStatus::Fail)
            ->and($result->detail['problems'])->toContain('server:main:admin: The admin account is ROOT-LIKE.');
    });

    it('folds a warning finding (no errors) into a warn DoctorResult', function (): void {
        $result = PackageToolsCheck::aggregate([
            DoctorFinding::ok('server:main:reachable', 'Server is reachable.'),
            DoctorFinding::warning('secrets:driver', 'Key lives next to the ciphertext.'),
        ]);

        expect($result->status)->toBe(DoctorStatus::Warn);
    });

    it('folds all-healthy findings into a pass DoctorResult', function (): void {
        $result = PackageToolsCheck::aggregate([
            DoctorFinding::ok('secrets:driver', 'Secret driver: kms.'),
        ]);

        expect($result->status)->toBe(DoctorStatus::Pass);
    });

    it('skips when there are no findings', function (): void {
        expect(PackageToolsCheck::aggregate([])->status)->toBe(DoctorStatus::Skip);
    });
});

<?php

declare(strict_types=1);

use Simtabi\Laranail\DBConsole\Enums\Severity;
use Simtabi\Laranail\DBConsole\Doctor\DoctorFinding;
use Simtabi\Laranail\DBConsole\Doctor\OperatorSecurityCheck;
use Simtabi\Laranail\DBConsole\Tests\Fixtures\OperatorSecurity;

beforeEach(fn () => $this->migrateCatalog());

function finding(string $check): ?DoctorFinding
{
    foreach (app(OperatorSecurityCheck::class)->findings() as $finding) {
        if ($finding->check === $check) {
            return $finding;
        }
    }

    return null;
}

function inProduction(Closure $assertions): void
{
    app()->detectEnvironment(fn (): string => 'production');

    try {
        $assertions();
    } finally {
        app()->detectEnvironment(fn (): string => 'testing');
    }
}

it('errors when enforcement is off in production, and warns elsewhere', function (): void {
    OperatorSecurity::setUp();
    config()->set('laranail.db-console.security.assurance.enforce', 'off');

    expect(finding('security:assurance')?->severity)->toBe(Severity::Warning);
    inProduction(function (): void {
        expect(finding('security:assurance')?->severity)->toBe(Severity::Error);
    });
});

it('warns when report is left on in production', function (): void {
    OperatorSecurity::setUp();
    config()->set('laranail.db-console.security.assurance.enforce', 'report');

    inProduction(function (): void {
        expect(finding('security:assurance')?->severity)->toBe(Severity::Warning);
    });
});

it('warns when the host binds no OperatorAssurance', function (): void {
    OperatorSecurity::setUp(bindHostAssurance: false);

    expect(finding('security:assurance-binding')?->severity)->toBe(Severity::Warning);
});

it('is clean on the default require in production with a host binding and an enrolled Owner', function (): void {
    OperatorSecurity::setUp();
    config()->set('laranail.db-console.security.assurance.enforce');
    OperatorSecurity::owner(OperatorSecurity::enrolled());

    inProduction(function (): void {
        expect(finding('security:assurance')?->severity)->toBe(Severity::Info)
            ->and(finding('security:assurance-binding')?->severity)->toBe(Severity::Info)
            ->and(finding('security:owners'))->toBeNull();
    });
});

it('warns when no global Owner has a second factor', function (): void {
    OperatorSecurity::setUp();
    config()->set('laranail.db-console.security.assurance.enforce', 'require');
    OperatorSecurity::owner(OperatorSecurity::unenrolled());

    expect(finding('security:owners')?->severity)->toBe(Severity::Warning);
});

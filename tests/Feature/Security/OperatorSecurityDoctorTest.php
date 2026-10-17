<?php

declare(strict_types=1);

use Simtabi\Laranail\DBConsole\Enums\Severity;
use Simtabi\Laranail\DBConsole\Doctor\DoctorFinding;
use Simtabi\Laranail\DBConsole\Doctor\OperatorSecurityCheck;
use Simtabi\Laranail\DBConsole\Tests\Fixtures\OperatorSecurity;

beforeEach(function (): void {
    $this->migrateCatalog();
    OperatorSecurity::setUp();
});

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

it('warns whenever two-factor enforcement is off, in any environment', function (): void {
    config()->set('laranail.db-console.security.two_factor.enforce', 'off');

    expect(finding('security:two-factor')?->severity)->toBe(Severity::Warning);
    inProduction(function (): void {
        expect(finding('security:two-factor')?->severity)->toBe(Severity::Warning);
    });
});

it('is clean on the default require in production with an enrolled Owner', function (): void {
    config()->set('laranail.db-console.security.two_factor.enforce');
    OperatorSecurity::owner(OperatorSecurity::enrolled());

    inProduction(function (): void {
        expect(finding('security:two-factor')?->severity)->toBe(Severity::Info)
            ->and(finding('security:owners'))->toBeNull();
    });
});

it('errors on require with the null driver, which no one can satisfy', function (): void {
    config()->set('laranail.db-console.security.two_factor.enforce', 'require');
    config()->set('laranail.db-console.security.two_factor.driver', 'null');

    expect(finding('security:two-factor-driver')?->severity)->toBe(Severity::Error);
});

it('warns when no global Owner is enrolled', function (): void {
    config()->set('laranail.db-console.security.two_factor.enforce', 'require');
    OperatorSecurity::owner(OperatorSecurity::unenrolled());

    expect(finding('security:owners')?->severity)->toBe(Severity::Warning);
});

it('refuses the none step-up driver in production, and warns elsewhere', function (): void {
    config()->set('laranail.db-console.security.step_up.driver', 'none');

    expect(finding('security:step-up')?->severity)->toBe(Severity::Warning);
    inProduction(function (): void {
        expect(finding('security:step-up')?->severity)->toBe(Severity::Error);
    });
});

it('errors on the totp driver without authkit, and is clean with it', function (): void {
    config()->set('laranail.db-console.security.step_up.driver', 'totp');

    expect(finding('security:step-up')?->severity)->toBe(Severity::Error);

    OperatorSecurity::fakeAuthKit();
    expect(finding('security:step-up')?->severity)->toBe(Severity::Info);
});

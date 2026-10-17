<?php

declare(strict_types=1);

use Simtabi\Laranail\DBConsole\Enums\Hosting;
use Simtabi\Laranail\DBConsole\Enums\Severity;
use Simtabi\Laranail\DBConsole\Enums\EngineType;
use Simtabi\Laranail\DBConsole\Doctor\HostingCheck;
use Simtabi\Laranail\DBConsole\Doctor\DoctorFinding;

/**
 * @param array<string, mixed> $probe
 *
 * @return array<string, DoctorFinding>
 */
function hostingDoctor(Hosting $hosting, EngineType $engine, ?string $host = 'db.internal', array $probe = []): array
{
    $out = [];
    foreach ((new HostingCheck)->findings('main', $hosting, $engine, $host, $probe) as $finding) {
        $out[$finding->check] = $finding;
    }

    return $out;
}

it('reports the hosting a server declares', function (): void {
    $finding = hostingDoctor(Hosting::DigitalOcean, EngineType::Mysql)['server:main:hosting'];

    expect($finding->severity)->toBe(Severity::Info)
        ->and($finding->message)->toContain('DigitalOcean')
        ->and($finding->message)->toContain('point-in-time');
});

it('warns when a self-hosted server is plainly a managed one', function (string $host, string $value): void {
    $finding = hostingDoctor(Hosting::SelfHosted, EngineType::Pgsql, $host)['server:main:hosting'];

    expect($finding->severity)->toBe(Severity::Warning)
        ->and($finding->remediation)->toContain("servers.main.hosting = '{$value}'");
})->with([
    ['db-pg-ams3-1.b.db.ondigitalocean.com', 'digitalocean'],
    ['shop.c9akciq32.eu-west-1.rds.amazonaws.com', 'rds'],
]);

it('is quiet about a self-hosted server that looks self-hosted', function (): void {
    expect(hostingDoctor(Hosting::SelfHosted, EngineType::Mysql)['server:main:hosting']->severity)->toBe(Severity::Info);
});

it('warns when the admin cannot read mysql.user', function (): void {
    $finding = hostingDoctor(Hosting::DigitalOcean, EngineType::Mysql, probe: ['mysql_user_readable' => false])['server:main:accounts'];

    expect($finding->severity)->toBe(Severity::Warning)
        ->and($finding->message)->toContain('mysql.user')
        ->and($finding->remediation)->toContain('GRANT SELECT ON mysql.user');
});

it('passes an admin that can read mysql.user, and says nothing when it was not asked', function (): void {
    expect(hostingDoctor(Hosting::SelfHosted, EngineType::Mariadb, probe: ['mysql_user_readable' => true])['server:main:accounts']->severity)
        ->toBe(Severity::Info)
        ->and(hostingDoctor(Hosting::SelfHosted, EngineType::Mysql))->not->toHaveKey('server:main:accounts');
});

it('names the server settings a managed MySQL enforces', function (): void {
    $finding = hostingDoctor(Hosting::DigitalOcean, EngineType::Mysql, probe: [
        'sql_require_primary_key' => 'ON',
        'gtid_mode'               => 'ON',
    ])['server:main:server-settings'];

    expect($finding->severity)->toBe(Severity::Info)
        ->and($finding->message)->toContain('sql_require_primary_key=ON')
        ->and($finding->message)->toContain('gtid_mode=ON');
});

it('warns that a forced drop can fail without pg_signal_backend', function (): void {
    $finding = hostingDoctor(Hosting::DigitalOcean, EngineType::Pgsql, probe: ['can_signal_backends' => false])['server:main:forced-drop'];

    expect($finding->severity)->toBe(Severity::Warning)
        ->and($finding->remediation)->toContain('GRANT pg_signal_backend');
});

it('passes a Postgres admin that can end other sessions, and skips the check elsewhere', function (): void {
    expect(hostingDoctor(Hosting::SelfHosted, EngineType::Pgsql, probe: ['can_signal_backends' => true])['server:main:forced-drop']->severity)
        ->toBe(Severity::Info)
        ->and(hostingDoctor(Hosting::SelfHosted, EngineType::Mysql, probe: ['can_signal_backends' => false]))
        ->not->toHaveKey('server:main:forced-drop');
});

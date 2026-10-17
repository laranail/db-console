<?php

declare(strict_types=1);

use Simtabi\Laranail\DBConsole\Enums\Severity;
use Simtabi\Laranail\DBConsole\Doctor\ConfigCheck;
use Simtabi\Laranail\DBConsole\Doctor\DoctorFinding;

function configFinding(string $check): DoctorFinding
{
    return collect(app(ConfigCheck::class)->findings())->firstWhere('check', $check);
}

it('passes a config with no retired api keys', function (): void {
    expect(configFinding('config:legacy-api-keys')->severity)->toBe(Severity::Info);
});

it('fails a config that still sets api.guard or api.prefix, naming the replacement', function (): void {
    config()->set('laranail.db-console.api.guard', 'passport');
    config()->set('laranail.db-console.api.prefix', 'legacy');

    $finding = configFinding('config:legacy-api-keys');

    expect($finding->isError())->toBeTrue()
        ->and($finding->message)->toContain('api.guard (now api.auth)')->toContain('api.prefix (now api.path)');
});

it('passes the default refuse policy', function (): void {
    expect(configFinding('config:on-existing')->severity)->toBe(Severity::Info);
});

it('warns when existing resources are adopted by default, globally or for a server', function (): void {
    config()->set('laranail.db-console.databases.on_existing', 'adopt');
    config()->set('laranail.db-console.servers.primary.accounts.on_existing', 'adopt_and_apply');

    $finding = configFinding('config:on-existing');

    expect($finding->severity)->toBe(Severity::Warning)
        ->and($finding->message)->toContain('databases.on_existing=adopt')
        ->toContain('servers.primary.accounts.on_existing=adopt_and_apply');
});

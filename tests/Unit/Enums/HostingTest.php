<?php

declare(strict_types=1);

use Simtabi\Laranail\DBConsole\Enums\Hosting;
use Simtabi\Laranail\DBConsole\Enums\EngineType;
use Simtabi\Laranail\DBConsole\Domain\HostingProfile;

/*
 * Where a server runs decides what DBConsole may assume about it: which
 * accounts belong to the provider, which host a new account gets, and whether
 * a dump or the provider's point-in-time recovery is the restore path.
 * Self-hosted is the default and must change nothing.
 */

it('has a profile for every hosting and engine pair', function (): void {
    $pairs = 0;

    foreach (Hosting::cases() as $hosting) {
        foreach (EngineType::cases() as $engine) {
            expect($hosting->profile($engine))->toBeInstanceOf(HostingProfile::class)
                ->and($hosting->profile($engine)->hosting)->toBe($hosting);
            $pairs++;
        }
    }

    expect($pairs)->toBe(count(Hosting::cases()) * count(EngineType::cases()))
        ->and($pairs)->toBeGreaterThanOrEqual(25);
});

it('changes nothing for a self-hosted server', function (EngineType $engine): void {
    $profile = Hosting::SelfHosted->profile($engine);

    expect($profile->defaultAccountHost)->toBeNull()
        ->and($profile->reservedAccounts)->toBe([])
        ->and($profile->providerBackups)->toBeFalse()
        ->and($profile->isReserved('mysql.sys'))->toBeFalse()
        ->and($profile->isReserved('doadmin'))->toBeFalse();
})->with(EngineType::cases());

it('gives every managed MySQL-family account the any-host wildcard', function (Hosting $hosting, EngineType $engine): void {
    expect($hosting->profile($engine)->defaultAccountHost)->toBe('%');
})->with([Hosting::DigitalOcean, Hosting::Rds, Hosting::CloudSql, Hosting::Generic])
    ->with([EngineType::Mysql, EngineType::Mariadb]);

it('leaves the account host alone where the engine has no host scoping', function (Hosting $hosting): void {
    expect($hosting->profile(EngineType::Pgsql)->defaultAccountHost)->toBeNull();
})->with([Hosting::DigitalOcean, Hosting::Rds, Hosting::CloudSql, Hosting::Generic]);

it('treats the provider\'s point-in-time recovery as the primary backup', function (Hosting $hosting): void {
    expect($hosting->profile(EngineType::Mysql)->providerBackups)->toBeTrue()
        ->and($hosting->profile(EngineType::Pgsql)->providerBackups)->toBeTrue();
})->with([Hosting::DigitalOcean, Hosting::Rds, Hosting::CloudSql, Hosting::Generic]);

it('recognises the provider\'s own accounts', function (Hosting $hosting, EngineType $engine, string $account, bool $reserved): void {
    expect($hosting->profile($engine)->isReserved($account))->toBe($reserved);
})->with([
    'DO admin'                     => [Hosting::DigitalOcean, EngineType::Mysql, 'doadmin', true],
    'DO internal'                  => [Hosting::DigitalOcean, EngineType::Pgsql, '_dodb', true],
    'MySQL system account'         => [Hosting::DigitalOcean, EngineType::Mysql, 'mysql.sys', true],
    'MariaDB system'               => [Hosting::Generic, EngineType::Mariadb, 'mariadb.sys', true],
    'RDS admin'                    => [Hosting::Rds, EngineType::Pgsql, 'rdsadmin', true],
    'RDS role'                     => [Hosting::Rds, EngineType::Pgsql, 'rds_superuser', true],
    'Cloud SQL'                    => [Hosting::CloudSql, EngineType::Mysql, 'cloudsqlsuperuser', true],
    'app account on DO'            => [Hosting::DigitalOcean, EngineType::Mysql, 'shop_app', false],
    'lookalike prefix'             => [Hosting::DigitalOcean, EngineType::Mysql, 'doadmin_shop', false],
    'mysql. only matches a prefix' => [Hosting::Generic, EngineType::Mysql, 'mysqlapp', false],
]);

it('recognises a provider from the host name alone', function (?string $host, ?Hosting $expected): void {
    expect(Hosting::detectFromHost($host))->toBe($expected);
})->with([
    'DigitalOcean'      => ['db-mysql-nyc3-12345-do-user-1-0.b.db.ondigitalocean.com', Hosting::DigitalOcean],
    'DigitalOcean caps' => ['DB-PG-AMS3-1.B.DB.ONDIGITALOCEAN.COM', Hosting::DigitalOcean],
    'RDS'               => ['shop.c9akciq32.us-east-1.rds.amazonaws.com', Hosting::Rds],
    'loopback'          => ['127.0.0.1', null],
    'plain host'        => ['db.internal', null],
    'lookalike'         => ['ondigitalocean.com.evil.test', null],
    'none'              => [null, null],
]);

it('reads its config value, and an unknown one is not silently self-hosted', function (): void {
    expect(Hosting::from('digitalocean'))->toBe(Hosting::DigitalOcean)
        ->and(Hosting::from('self_hosted'))->toBe(Hosting::SelfHosted)
        ->and(Hosting::tryFrom('digital-ocean'))->toBeNull();
});

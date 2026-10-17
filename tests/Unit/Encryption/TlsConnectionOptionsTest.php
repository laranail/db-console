<?php

declare(strict_types=1);

use Pdo\Mysql;
use Simtabi\Laranail\DBConsole\Enums\EngineType;
use Simtabi\Laranail\DBConsole\Servers\ServerDefinition;
use Simtabi\Laranail\DBConsole\Encryption\TlsConnectionOptions;

/**
 * @param array<string, mixed> $tls
 */
function tlsDefinition(EngineType $engine, array $tls): ServerDefinition
{
    return new ServerDefinition(
        name: 'primary',
        engine: $engine,
        connection: 'admin',
        tls: [...['enabled' => true, 'verify' => true, 'ca' => null, 'cert' => null, 'key' => null], ...$tls],
    );
}

it('leaves the connection untouched when TLS is disabled', function (): void {
    $connection = ['driver' => 'pgsql', 'host' => 'db.example.com', 'sslmode' => 'prefer'];

    expect(new TlsConnectionOptions()->apply(tlsDefinition(EngineType::Pgsql, ['enabled' => false]), $connection))
        ->toBe($connection);
});

describe('pgsql', function (): void {
    it('requires a verified certificate and points at the CA', function (): void {
        $applied = new TlsConnectionOptions()->apply(
            tlsDefinition(EngineType::Pgsql, ['ca' => '/certs/do-ca.crt']),
            ['driver' => 'pgsql', 'host' => 'db.example.com', 'sslmode' => 'prefer'],
        );

        expect($applied)->toMatchArray(['sslmode' => 'verify-full', 'sslrootcert' => '/certs/do-ca.crt'])
            ->and($applied)->not->toHaveKeys(['sslcert', 'sslkey']);
    });

    it('requires TLS without verification when verify is off', function (): void {
        $applied = new TlsConnectionOptions()->apply(
            tlsDefinition(EngineType::Pgsql, ['verify' => false]),
            ['driver' => 'pgsql', 'sslmode' => 'disable'],
        );

        expect($applied['sslmode'])->toBe('require');
    });

    it('keeps a root certificate set on the connection when the TLS block names none', function (): void {
        $applied = new TlsConnectionOptions()->apply(
            tlsDefinition(EngineType::Pgsql, []),
            ['driver' => 'pgsql', 'sslrootcert' => '/etc/ssl/own.crt'],
        );

        expect($applied['sslrootcert'])->toBe('/etc/ssl/own.crt');
    });
});

describe('mysql', function (): void {
    it('sets the CA, client pair and verification as PDO options', function (): void {
        $applied = new TlsConnectionOptions()->apply(
            tlsDefinition(EngineType::Mysql, ['ca' => '/certs/ca.pem', 'cert' => '/certs/c.pem', 'key' => '/certs/k.pem']),
            ['driver' => 'mysql', 'options' => [PDO::ATTR_TIMEOUT => 5, Mysql::ATTR_SSL_CA => '/stale.pem']],
        );

        expect($applied['options'])->toBe([
            Mysql::ATTR_SSL_CA                 => '/certs/ca.pem',
            Mysql::ATTR_SSL_CERT               => '/certs/c.pem',
            Mysql::ATTR_SSL_KEY                => '/certs/k.pem',
            Mysql::ATTR_SSL_VERIFY_SERVER_CERT => true,
            PDO::ATTR_TIMEOUT                  => 5,
        ]);
    });

    it('applies to the mariadb driver and keeps a CA the connection already names', function (): void {
        $applied = new TlsConnectionOptions()->apply(
            tlsDefinition(EngineType::Mariadb, ['verify' => false]),
            ['driver' => 'mariadb', 'options' => [Mysql::ATTR_SSL_CA => '/own.pem']],
        );

        expect($applied['options'])->toBe([
            Mysql::ATTR_SSL_VERIFY_SERVER_CERT => false,
            Mysql::ATTR_SSL_CA                 => '/own.pem',
        ]);
    });
});

it('encrypts sqlsrv and trusts the certificate only when verify is off', function (): void {
    $options = new TlsConnectionOptions;

    expect($options->apply(tlsDefinition(EngineType::Sqlsrv, []), ['driver' => 'sqlsrv']))
        ->toMatchArray(['encrypt' => 'yes', 'trust_server_certificate' => 'no'])
        ->and($options->apply(tlsDefinition(EngineType::Sqlsrv, ['verify' => false]), ['driver' => 'sqlsrv']))
        ->toMatchArray(['encrypt' => 'yes', 'trust_server_certificate' => 'yes']);
});

it('leaves sqlite alone', function (): void {
    $connection = ['driver' => 'sqlite', 'database' => ':memory:'];

    expect(new TlsConnectionOptions()->apply(tlsDefinition(EngineType::Sqlite, []), $connection))->toBe($connection);
});

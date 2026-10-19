<?php

declare(strict_types=1);

use Simtabi\Laranail\DBConsole\Tunnels\Endpoint;
use Simtabi\Laranail\DBConsole\Tunnels\TunnelledConnection;

$local = Endpoint::tcp('127.0.0.1', 41000);
$remote = Endpoint::tcp('db.internal', 5432);

it('points a MySQL connection at the forwarded port and keeps everything else', function () use ($local, $remote): void {
    $config = TunnelledConnection::rewrite(
        ['driver' => 'mysql', 'host' => 'db.internal', 'port' => 3306, 'unix_socket' => '', 'username' => 'admin', 'options' => [1 => 'x']],
        $local,
        $remote,
    );

    expect($config)->toMatchArray(['host' => '127.0.0.1', 'port' => 41000, 'username' => 'admin', 'options' => [1 => 'x']]);
});

it('points MySQL at a local socket through unix_socket', function (): void {
    $config = TunnelledConnection::rewrite(
        ['driver' => 'mariadb', 'host' => 'db.internal', 'port' => 3306],
        Endpoint::socket('/tmp/dbc-1/db.sock'),
        Endpoint::tcp('db.internal', 3306),
    );

    expect($config['unix_socket'])->toBe('/tmp/dbc-1/db.sock');
});

it('points Postgres at a socket directory, where libpq looks for .s.PGSQL.<port>', function (): void {
    $config = TunnelledConnection::rewrite(
        ['driver' => 'pgsql', 'host' => 'db.internal', 'port' => 5432],
        Endpoint::socket('/tmp/dbc-1/' . TunnelledConnection::socketName('pgsql')),
        Endpoint::tcp('db.internal', 5432),
    );

    expect($config)->toMatchArray(['host' => '/tmp/dbc-1', 'port' => 5432])
        ->and(TunnelledConnection::socketName('pgsql'))->toBe('.s.PGSQL.5432');
});

// verify-full against 127.0.0.1 fails for every real certificate. hostaddr
// dials the forward while libpq verifies the name the certificate carries.
it('keeps Postgres verifying the real host name through the tunnel', function () use ($local, $remote): void {
    $config = TunnelledConnection::rewrite(
        ['driver' => 'pgsql', 'host' => 'db.internal', 'port' => 5432, 'sslmode' => 'verify-full'],
        $local,
        $remote,
    );

    expect($config['host'])->toBe('db.internal;hostaddr=127.0.0.1')
        ->and($config['port'])->toBe(41000);
});

it('dials the loopback plainly when no host name is being verified', function (string $sslmode, Endpoint $target) use ($local): void {
    $config = TunnelledConnection::rewrite(
        ['driver' => 'pgsql', 'host' => 'db.internal', 'port' => 5432, 'sslmode' => $sslmode],
        $local,
        $target,
    );

    expect($config['host'])->toBe('127.0.0.1');
})->with([
    'verify-ca'                => ['verify-ca', Endpoint::tcp('db.internal', 5432)],
    'require'                  => ['require', Endpoint::tcp('db.internal', 5432)],
    'loopback on the SSH host' => ['verify-full', Endpoint::tcp('127.0.0.1', 5432)],
    'socket on the SSH host'   => ['verify-full', Endpoint::socket('/var/run/postgresql/.s.PGSQL.5432')],
]);

it('expands a url so it cannot override the rewritten host', function () use ($local, $remote): void {
    $config = TunnelledConnection::rewrite(
        ['driver' => 'pgsql', 'url' => sprintf('pgsql://admin:%s@db.internal:25060/defaultdb', rawurlencode(testPassword()))],
        $local,
        $remote,
    );

    expect($config)->not->toHaveKey('url')
        ->and($config)->toMatchArray(['host' => '127.0.0.1', 'port' => 41000, 'database' => 'defaultdb', 'username' => 'admin'])
        ->and(TunnelledConnection::origin(['driver' => 'pgsql', 'url' => sprintf('pgsql://admin:%s@db.internal:25060/defaultdb', rawurlencode(testPassword()))]))
        ->toBe(['host' => 'db.internal', 'port' => 25060]);
});

it('refuses what one tunnel cannot carry', function (array $config, Endpoint $through, string $message) use ($remote): void {
    expect(fn (): array => TunnelledConnection::rewrite($config, $through, $remote))->toThrow(InvalidArgumentException::class, $message);
})->with([
    'read/write split'   => [['driver' => 'mysql', 'read' => ['host' => ['a']], 'write' => ['host' => ['b']]], Endpoint::tcp('127.0.0.1', 1), 'read/write split'],
    'sqlite'             => [['driver' => 'sqlite', 'database' => ':memory:'], Endpoint::tcp('127.0.0.1', 1), 'cannot be tunnelled'],
    'sqlsrv over socket' => [['driver' => 'sqlsrv', 'host' => 'db'], Endpoint::socket('/tmp/x'), 'Unix socket'],
]);

it('recognises a connection that already uses a local socket', function (array $config, bool $socket): void {
    expect(TunnelledConnection::usesSocket($config))->toBe($socket);
})->with([
    'mysql unix_socket'   => [['driver' => 'mysql', 'host' => '127.0.0.1', 'unix_socket' => '/var/run/mysqld/mysqld.sock'], true],
    'mysql empty socket'  => [['driver' => 'mysql', 'host' => 'db', 'unix_socket' => ''], false],
    'postgres socket dir' => [['driver' => 'pgsql', 'host' => '/var/run/postgresql'], true],
    'postgres tcp'        => [['driver' => 'pgsql', 'host' => 'db.internal'], false],
    'sqlsrv'              => [['driver' => 'sqlsrv', 'host' => '/odd'], false],
]);

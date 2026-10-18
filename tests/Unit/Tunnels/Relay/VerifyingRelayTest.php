<?php

declare(strict_types=1);

use Simtabi\Laranail\DBConsole\Enums\EngineType;
use Simtabi\Laranail\DBConsole\Tunnels\Endpoint;
use Simtabi\Laranail\DBConsole\Servers\ServerDefinition;
use Simtabi\Laranail\DBConsole\Tunnels\Relay\VerifyingRelay;

function relayDefinition(): ServerDefinition
{
    return new ServerDefinition('behind-bastion', EngineType::Mysql, 'admin', [
        'enabled' => true, 'verify' => true, 'ca' => '/certs/ca.pem', 'cert' => '/certs/client.pem', 'key' => '/certs/client.key',
    ]);
}

// The relay is told the tunnel's own end, whichever kind it is. A socket
// passed as a TCP address reached the relay as `tcp://:`, and only the live
// job noticed.
it('passes a socket-ended tunnel as its socket, and a port-ended one as its address', function (Endpoint $upstream, array $expected): void {
    $settings = VerifyingRelay::settings(relayDefinition(), $upstream, Endpoint::tcp('db.internal', 3306), '/run/dbc-1/mysql-tls.sock', 15);

    expect($settings['upstream'])->toBe($expected);
})->with([
    'socket' => [Endpoint::socket('/run/dbc-1/db.sock'), ['socket' => '/run/dbc-1/db.sock']],
    'port'   => [Endpoint::tcp('127.0.0.1', 41000), ['host' => '127.0.0.1', 'port' => 41000]],
]);

it('verifies the real name with the server\'s CA and client certificate', function (): void {
    $settings = VerifyingRelay::settings(relayDefinition(), Endpoint::tcp('127.0.0.1', 41000), Endpoint::tcp('db.internal', 3306), '/run/dbc-1/mysql-tls.sock', 15);

    expect($settings)->toMatchArray([
        'listen'    => '/run/dbc-1/mysql-tls.sock',
        'peer_name' => 'db.internal',
        'tls'       => ['cafile' => '/certs/ca.pem', 'local_cert' => '/certs/client.pem', 'local_pk' => '/certs/client.key'],
        'timeout'   => 15,
    ]);
});

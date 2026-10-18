<?php

declare(strict_types=1);

use Simtabi\Laranail\DBConsole\Tunnels\Relay\MysqlPacket;
use Simtabi\Laranail\DBConsole\Tunnels\Relay\MysqlHandshake;
use Simtabi\Laranail\DBConsole\Tunnels\Relay\MysqlPacketReader;

/**
 * A protocol-10 server greeting: version, connection id, 8 bytes of auth
 * data, filler, the lower capability flags, then the rest.
 */
function greeting(int $capabilities): MysqlPacket
{
    return new MysqlPacket(0, "\x0a" . "8.4.3\0" . pack('V', 7) . str_repeat('a', 8) . "\0"
        . pack('v', $capabilities & 0xFFFF) . "\xff" . pack('v', 2) . pack('v', $capabilities >> 16)
        . "\x15" . str_repeat("\0", 10) . str_repeat('b', 12) . "\0" . "caching_sha2_password\0");
}

function handshakeResponse(int $capabilities): MysqlPacket
{
    return new MysqlPacket(1, pack('V', $capabilities) . pack('V', 16_777_216) . "\xff" . str_repeat("\0", 23)
        . "root\0" . "\x20" . str_repeat('s', 32) . "db_console_test\0" . "caching_sha2_password\0");
}

it('frames and parses packets, across reads split anywhere', function (): void {
    $wire = new MysqlPacket(3, 'hello')->encode() . new MysqlPacket(4, str_repeat('x', 300))->encode();
    $reader = new MysqlPacketReader;

    $packets = [];
    foreach (str_split($wire, 7) as $chunk) {
        $reader->feed($chunk);
        while (($packet = $reader->next()) instanceof MysqlPacket) {
            $packets[] = $packet;
        }
    }

    expect($wire)->toStartWith("\x05\x00\x00\x03hello")
        ->and($packets)->toHaveCount(2)
        ->and($packets[0]->sequence)->toBe(3)
        ->and($packets[1]->payload)->toBe(str_repeat('x', 300))
        ->and($reader->pending())->toBe('');
});

it('hides TLS from the client, which then talks plaintext to the relay', function (): void {
    $caps = MysqlHandshake::CLIENT_PROTOCOL_41 | MysqlHandshake::CLIENT_SSL | 0x0008_0000;
    $plain = MysqlHandshake::withoutSsl(greeting($caps));

    expect(MysqlHandshake::serverSupportsSsl(greeting($caps)))->toBeTrue()
        ->and(MysqlHandshake::serverSupportsSsl($plain))->toBeFalse()
        ->and(MysqlHandshake::capabilities($plain))->toBe($caps & ~MysqlHandshake::CLIENT_SSL)
        ->and(strlen($plain->payload))->toBe(strlen(greeting($caps)->payload));
});

it('builds the SSLRequest from the client response, then renumbers the response after it', function (): void {
    $caps = MysqlHandshake::CLIENT_PROTOCOL_41 | 0x0008_0000;
    $response = handshakeResponse($caps);

    $request = MysqlHandshake::sslRequest($response);
    $forwarded = MysqlHandshake::afterSslRequest($response);

    expect($request->sequence)->toBe(1)
        ->and(strlen($request->payload))->toBe(32)
        ->and(unpack('V', $request->payload)[1])->toBe($caps | MysqlHandshake::CLIENT_SSL)
        ->and(substr($request->payload, 4, 5))->toBe(substr($response->payload, 4, 5))
        ->and($forwarded->sequence)->toBe(2)
        ->and(unpack('V', $forwarded->payload)[1])->toBe($caps | MysqlHandshake::CLIENT_SSL)
        ->and(substr($forwarded->payload, 4))->toBe(substr($response->payload, 4));
});

it('refuses a client response without the 4.1 protocol', function (): void {
    expect(fn (): MysqlPacket => MysqlHandshake::sslRequest(handshakeResponse(0)))
        ->toThrow(UnexpectedValueException::class, 'protocol 4.1');
});

it('ends the connection phase on OK or ERR, not on an auth switch or more data', function (string $first, bool $ends): void {
    expect(MysqlHandshake::endsConnectionPhase(new MysqlPacket(2, $first . 'rest')))->toBe($ends);
})->with([
    'OK'             => ["\x00", true],
    'ERR'            => ["\xff", true],
    'auth more data' => ["\x01", false],
    'auth switch'    => ["\xfe", false],
]);

it('writes an ERR packet the client reports as its own error', function (): void {
    $packet = MysqlHandshake::error(2, 'TLS to db.example.com failed: certificate did not match');

    expect($packet->sequence)->toBe(2)
        ->and($packet->payload)->toStartWith("\xff" . pack('v', 2026) . '#08S01')
        ->and($packet->payload)->toEndWith('certificate did not match');
});

<?php

declare(strict_types=1);

/*
 * A MySQL server just real enough for the relay: it greets offering TLS,
 * takes the SSLRequest, starts TLS with the given certificate, asks for full
 * authentication (so the exchange has a client turn after the response),
 * answers OK, then answers one COM_QUERY with an OK whose info string echoes
 * the query. Every packet's sequence number is checked against the protocol.
 *
 * It listens on a loopback port, because PHP starts TLS only on tcp://
 * streams, and prints `READY <port>`.
 *
 * Usage: php fake-mysql-tls-server.php <cert> <key>
 */

[, $cert, $key] = $argv;

function frame(int $seq, string $payload): string
{
    $n = strlen($payload);

    return chr($n & 0xFF) . chr(($n >> 8) & 0xFF) . chr($n >> 16) . chr($seq) . $payload;
}

/** @return array{0: int, 1: string} */
function packet($stream): array
{
    $header = '';
    while (strlen($header) < 4) {
        $chunk = fread($stream, 4 - strlen($header));
        if ($chunk === false || $chunk === '') {
            exit(3);
        }
        $header .= $chunk;
    }
    $length = ord($header[0]) | (ord($header[1]) << 8) | (ord($header[2]) << 16);
    $payload = '';
    while (strlen($payload) < $length) {
        $chunk = fread($stream, $length - strlen($payload));
        if ($chunk === false || $chunk === '') {
            exit(3);
        }
        $payload .= $chunk;
    }

    return [ord($header[3]), $payload];
}

function expectSequence(int $got, int $want, string $what): void
{
    if ($got !== $want) {
        fwrite(STDERR, "{$what}: sequence {$got}, expected {$want}\n");
        exit(4);
    }
}

$server = stream_socket_server('tcp://127.0.0.1:0', $errno, $error, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN,
    stream_context_create(['ssl' => ['local_cert' => $cert, 'local_pk' => $key]]));
if ($server === false) {
    fwrite(STDERR, "listen: {$error}\n");
    exit(2);
}
$name = (string) stream_socket_get_name($server, false);
fwrite(STDOUT, 'READY ' . substr($name, strrpos($name, ':') + 1) . "\n");

while ($conn = @stream_socket_accept($server, 30)) {
    // No read-ahead: a client may send its SSLRequest and TLS ClientHello in
    // one write, and a buffered fread() would hide the ClientHello from TLS.
    stream_set_read_buffer($conn, 0);

    // Protocol 41 | SSL | plugin auth, in the lower and upper halves.
    $caps = 0x0200 | 0x0800 | 0x8000 | 0x0008_0000;
    fwrite($conn, frame(0, "\x0a" . "8.4.0-fake\0" . pack('V', 1) . str_repeat('a', 8) . "\0" . pack('v', $caps & 0xFFFF)
        . "\xff" . pack('v', 2) . pack('v', $caps >> 16) . "\x15" . str_repeat("\0", 10) . str_repeat('b', 12) . "\0" . "caching_sha2_password\0"));

    [$seq, $request] = packet($conn);
    expectSequence($seq, 1, 'SSLRequest');
    if (strlen($request) !== 32 || ((unpack('V', $request)[1] & 0x0800) === 0)) {
        fwrite(STDERR, "not an SSLRequest\n");
        exit(4);
    }

    stream_set_blocking($conn, true);
    if (! @stream_socket_enable_crypto($conn, true, STREAM_CRYPTO_METHOD_TLSv1_2_SERVER | STREAM_CRYPTO_METHOD_TLSv1_3_SERVER)) {
        // The relay refused the certificate; it never gets a login.
        fclose($conn);

        continue;
    }

    [$seq, $response] = packet($conn);
    expectSequence($seq, 2, 'handshake response');

    fwrite($conn, frame(3, "\x01\x04"));            // perform full authentication
    [$seq, $password] = packet($conn);
    expectSequence($seq, 4, 'password');

    fwrite($conn, frame(5, "\x00\x00\x00\x02\x00\x00\x00"));   // OK

    [$seq, $query] = packet($conn);
    expectSequence($seq, 0, 'COM_QUERY');
    fwrite($conn, frame(1, "\x00\x00\x00\x02\x00\x00\x00" . 'echo:' . substr($query, 1) . ':' . $password));

    fclose($conn);
}

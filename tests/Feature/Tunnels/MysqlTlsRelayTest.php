<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;
use Simtabi\Laranail\DBConsole\Tunnels\Relay\MysqlPacket;
use Simtabi\Laranail\DBConsole\Tunnels\Relay\MysqlHandshake;
use Simtabi\Laranail\DBConsole\Tunnels\Support\WorkerProcess;
use Simtabi\Laranail\DBConsole\Tunnels\Relay\MysqlPacketReader;

/*
 * The relay end to end against a fake TLS MySQL server on a loopback port
 * (tests/Fixtures/fake-mysql-tls-server.php) with a certificate for `mytls`
 * from a CA made here. A raw client speaks plaintext to the relay over its
 * Unix socket, as PDO would. Needs local listening and the openssl CLI; outside CI a sandbox
 * without either skips, and in CI it must run.
 */

beforeEach(function (): void {
    $this->dir = sys_get_temp_dir() . '/dbcr-' . bin2hex(random_bytes(3));
    mkdir($this->dir, 0o700);

    $probe = @stream_socket_server("unix://{$this->dir}/probe.sock", $errno, $error);
    if ($probe === false || new Process(['openssl', 'version'])->run() !== 0) {
        getenv('CI') !== false
            ? $this->fail('the relay test needs local listening and openssl in CI')
            : $this->markTestSkipped('needs local listening and the openssl CLI');
    }
    fclose($probe);
    @unlink("{$this->dir}/probe.sock");

    $run = function (array $command): void {
        $process = new Process($command, $this->dir);
        $process->mustRun();
    };
    file_put_contents("{$this->dir}/ext", "subjectAltName=DNS:mytls\nbasicConstraints=CA:FALSE\n");
    $run(['openssl', 'req', '-x509', '-nodes', '-newkey', 'rsa:2048', '-days', '1', '-subj', '/CN=relay test CA', '-keyout', 'ca.key', '-out', 'ca.crt']);
    $run(['openssl', 'req', '-nodes', '-newkey', 'rsa:2048', '-subj', '/CN=mytls', '-keyout', 'server.key', '-out', 'server.csr']);
    $run(['openssl', 'x509', '-req', '-in', 'server.csr', '-CA', 'ca.crt', '-CAkey', 'ca.key', '-CAcreateserial', '-days', '1', '-out', 'server.crt', '-extfile', 'ext']);

    $this->server = new Process([PHP_BINARY, dirname(__DIR__, 2) . '/Fixtures/fake-mysql-tls-server.php', "{$this->dir}/server.crt", "{$this->dir}/server.key"]);
    $this->server->start();
    $this->server->waitUntil(fn (string $type, string $output): bool => preg_match('/READY \d+/', $output) === 1);
    $this->serverPort = (int) preg_replace('/\D/', '', $this->server->getOutput());
});

afterEach(function (): void {
    ($this->relay ?? null)?->stop();
    ($this->server ?? null)?->stop(1);
    foreach (glob("{$this->dir}/*") ?: [] as $file) {
        @unlink($file);
    }
    @rmdir($this->dir);
});

function startRelay(object $test, string $peerName): WorkerProcess
{
    return $test->relay = WorkerProcess::start(dirname(__DIR__, 3) . '/resources/bin/mysql-tls-relay.php', [
        'listen'    => "{$test->dir}/relay.sock",
        'upstream'  => ['host' => '127.0.0.1', 'port' => $test->serverPort],
        'peer_name' => $peerName,
        'tls'       => ['cafile' => "{$test->dir}/ca.crt"],
        'timeout'   => 5,
    ], 10, 'MySQL TLS relay');
}

/**
 * @param resource $stream
 */
function readMysqlPacket($stream, MysqlPacketReader $reader): MysqlPacket
{
    while (! ($packet = $reader->next()) instanceof MysqlPacket) {
        $bytes = fread($stream, 8192);
        if ($bytes === false || $bytes === '') {
            throw new RuntimeException('the relay closed the connection');
        }
        $reader->feed($bytes);
    }

    return $packet;
}

it('verifies the server, hides TLS from the client, and renumbers the login', function (): void {
    $relay = startRelay($this, 'mytls');
    $client = stream_socket_client("unix://{$relay->local->socket}", $errno, $error, 5);
    stream_set_timeout($client, 5);
    $reader = new MysqlPacketReader;

    $greeting = readMysqlPacket($client, $reader);
    expect($greeting->sequence)->toBe(0)
        ->and(MysqlHandshake::serverSupportsSsl($greeting))->toBeFalse();

    // A plaintext login, as a client that saw no TLS on offer sends it.
    fwrite($client, new MysqlPacket(1, pack('V', 0x0200 | 0x0008_0000) . pack('V', 1 << 24) . "\xff" . str_repeat("\0", 23) . "root\0\0")->encode());

    $more = readMysqlPacket($client, $reader);
    expect($more->sequence)->toBe(2)->and($more->payload)->toBe("\x01\x04");

    fwrite($client, new MysqlPacket(3, "secret\0")->encode());
    expect(readMysqlPacket($client, $reader)->sequence)->toBe(4);

    // Commands are not renumbered.
    fwrite($client, new MysqlPacket(0, "\x03SELECT 1")->encode());
    $reply = readMysqlPacket($client, $reader);

    expect($reply->sequence)->toBe(1)
        ->and($reply->payload)->toEndWith("echo:SELECT 1:secret\0");
});

it('refuses a server whose certificate does not name the host, and tells the client why', function (): void {
    $relay = startRelay($this, 'mytls-alias');
    $client = stream_socket_client("unix://{$relay->local->socket}", $errno, $error, 5);
    stream_set_timeout($client, 5);
    $reader = new MysqlPacketReader;

    readMysqlPacket($client, $reader);
    fwrite($client, new MysqlPacket(1, pack('V', 0x0200) . pack('V', 1 << 24) . "\xff" . str_repeat("\0", 23) . "root\0\0")->encode());

    $error = readMysqlPacket($client, $reader);

    expect($error->first())->toBe(0xFF)
        ->and($error->sequence)->toBe(2)
        ->and($error->payload)->toContain('TLS to mytls-alias failed')
        ->and($error->payload)->toContain('did not match');
});

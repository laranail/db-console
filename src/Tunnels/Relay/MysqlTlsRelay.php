<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DBConsole\Tunnels\Relay;

use Throwable;
use RuntimeException;
use UnexpectedValueException;

/**
 * Verified TLS to a MySQL server reached through a tunnel, run in its own PHP
 * process (resources/bin/mysql-tls-relay.php).
 *
 * pdo_mysql checks the server certificate against the host it dials, and a
 * tunnel makes that 127.0.0.1, so verification would compare the wrong name;
 * mysqlnd offers no peer-name option, unlike libpq's hostaddr. The relay does
 * the TLS step itself: it takes the client's place in MySQL's in-protocol TLS
 * upgrade and verifies the certificate against the database's real name and
 * CA. The client talks plaintext to the relay over a 0600 socket in the
 * tunnel's 0700 directory; everything past the relay is TLS.
 *
 * PHP starts TLS only on a tcp:// stream. When the tunnel's local end is a
 * Unix socket, the relay runs TLS over a loopback TCP pair of its own and
 * splices the pair's far end to the socket: the pair's listener exists only
 * until the relay's own connection is accepted, that one peer is checked, and
 * what crosses the pair is already TLS.
 *
 * A client whose server fails verification gets a MySQL ERR packet naming
 * why, so PDO reports it rather than a dropped connection.
 */
final class MysqlTlsRelay
{
    /** @var array<int, array{0: resource, 1: resource}> client and server, by client id */
    private array $pairs = [];

    /**
     * The sequence number the client expects next from the server, while its
     * connection phase runs: where an ERR telling it why has to go.
     */
    private int $clientExpects = 0;

    /**
     * Raw byte pipes, each end to the other: a loopback pair's far end and
     * the tunnel's socket. Keyed by the client they serve.
     *
     * @var array<int, array{0: resource, 1: resource}>
     */
    private array $splices = [];

    /**
     * @param array{
     *     listen: string,
     *     upstream: array{socket: string}|array{host: string, port: int},
     *     peer_name: string,
     *     tls: array{cafile?: ?string, local_cert?: ?string, local_pk?: ?string},
     *     timeout: int
     * } $settings
     * @param resource $stdin
     * @param resource $stdout
     */
    public function __construct(private readonly array $settings, private $stdin, private $stdout) {}

    public function run(): int
    {
        $socket = $this->settings['listen'];

        $umask = umask(0o177);
        try {
            $listener = @stream_socket_server("unix://{$socket}", $errno, $error);
        } finally {
            umask($umask);
        }

        if ($listener === false) {
            fwrite(STDERR, "could not listen on {$socket}: {$error}\n");

            return 5;
        }

        chmod($socket, 0o600);
        fwrite($this->stdout, "READY unix {$socket}\n");
        fflush($this->stdout);

        try {
            $this->loop($listener);
        } finally {
            fclose($listener);
            @unlink($socket);
        }

        return 0;
    }

    /**
     * @param resource $listener
     */
    private function loop($listener): void
    {
        while (true) {
            $read = [$listener, $this->stdin];
            foreach ($this->pairs as [$client, $server]) {
                $read[] = $client;
                $read[] = $server;
            }
            foreach ($this->splices as [$near, $far]) {
                $read[] = $near;
                $read[] = $far;
            }

            $write = $except = null;
            if (@stream_select($read, $write, $except, 1) === false) {
                continue;
            }

            foreach ($read as $stream) {
                if ($stream === $this->stdin) {
                    if (fgets($this->stdin) === false || feof($this->stdin)) {
                        $this->closeAll();

                        return;
                    }
                } elseif ($stream === $listener) {
                    $this->accept($listener);
                } else {
                    $this->pump($stream);
                }
            }
        }
    }

    /**
     * @param resource $listener
     */
    private function accept($listener): void
    {
        $client = @stream_socket_accept($listener, 0);
        if ($client === false) {
            return;
        }

        $id = (int) $client;
        stream_set_blocking($client, false);

        $this->clientExpects = 0;

        try {
            $this->pairs[$id] = [$client, $this->upgrade($id, $client)];
        } catch (RelayRefusal $e) {
            fwrite(STDERR, "relay refused a connection: {$e->getMessage()}\n");
            $this->drop($id, $client);
        } catch (Throwable $e) {
            // Whatever stopped the connection phase, the client hears why, as
            // the MySQL error its login fails with, not as a dropped socket.
            fwrite(STDERR, "relay refused a connection: {$e->getMessage()}\n");
            $this->refuse($client, $this->clientExpects, "the MySQL TLS relay could not connect to {$this->settings['peer_name']}: {$e->getMessage()}");
            $this->drop($id, $client);
        }
    }

    /**
     * The connection phase: greeting, TLS to the server with verification,
     * the client's response, then authentication, with each packet
     * renumbered across the extra SSLRequest. Returns the server stream once
     * the server has sent OK. Splices keep flowing throughout.
     *
     * @param resource $client
     *
     * @return resource
     */
    private function upgrade(int $id, $client)
    {
        $deadline = microtime(true) + max(1, $this->settings['timeout']);

        $server = $this->connectUpstream($id);

        try {
            return $this->handshake($client, $server, $deadline);
        } catch (Throwable $e) {
            @fclose($server);

            throw $e;
        }
    }

    /**
     * @param resource $client
     * @param resource $server
     *
     * @return resource
     */
    private function handshake($client, $server, float $deadline)
    {
        $fromServer = new MysqlPacketReader;
        $fromClient = new MysqlPacketReader;

        $greeting = $this->readPacket($server, $fromServer, $deadline);
        if (! MysqlHandshake::serverSupportsSsl($greeting)) {
            $this->refuse($client, 0, "TLS to {$this->settings['peer_name']} is required, and the server does not offer it");

            throw new RelayRefusal('the server does not offer TLS');
        }

        $this->send($client, MysqlHandshake::withoutSsl($greeting));
        $response = $this->readPacket($client, $fromClient, $deadline);
        $this->clientExpects = $response->sequence + 1;

        $this->send($server, MysqlHandshake::sslRequest($response));

        $failure = $this->startTls($server, $deadline);
        if ($failure !== null) {
            $this->refuse($client, $response->sequence + 1, "TLS to {$this->settings['peer_name']} failed: {$failure}");

            throw new RelayRefusal($failure);
        }

        $this->send($server, MysqlHandshake::afterSslRequest($response));

        // Authentication: either side may speak next (fast auth success is
        // followed by OK with nothing from the client), so wait on both.
        while (true) {
            if (($packet = $fromServer->next()) instanceof MysqlPacket) {
                $this->send($client, $packet->withSequence($packet->sequence - 1));
                $this->clientExpects = $packet->sequence;

                if (! MysqlHandshake::endsConnectionPhase($packet)) {
                    continue;
                }

                if ($packet->first() === 0xFF) {
                    // The client already has the server's own ERR.
                    throw new RelayRefusal('the server refused the login');
                }

                // Commands number from 0 on both sides, so from here bytes pass
                // as they are. Anything already read past OK goes on with them.
                $this->writeAll($server, $fromClient->pending());
                $this->writeAll($client, $fromServer->pending());

                return $server;
            }

            if (($packet = $fromClient->next()) instanceof MysqlPacket) {
                $this->send($server, $packet->withSequence($packet->sequence + 1));
                $this->clientExpects = $packet->sequence + 1;

                continue;
            }

            foreach ($this->service([$client, $server], $deadline) as $stream) {
                $bytes = $this->drain($stream);
                if ($bytes === null) {
                    throw new RuntimeException('a side closed during authentication');
                }

                ($stream === $server ? $fromServer : $fromClient)->feed($bytes);
            }
        }
    }

    /**
     * Wait until one of $streams is readable, moving spliced bytes in the
     * meantime. Returns the readable ones among $streams.
     *
     * @param list<resource> $streams
     *
     * @return list<resource>
     */
    private function service(array $streams, float $deadline): array
    {
        while (true) {
            $remaining = $deadline - microtime(true);
            if ($remaining <= 0) {
                throw new RuntimeException('timed out during the handshake');
            }

            $read = $streams;
            foreach ($this->splices as [$near, $far]) {
                $read[] = $near;
                $read[] = $far;
            }

            $write = $except = null;
            $seconds = (int) min(1, $remaining);
            $micros = $seconds === 0 ? (int) (min(1, $remaining) * 1_000_000) : 0;
            if (@stream_select($read, $write, $except, $seconds, $micros) === false) {
                continue;
            }

            $ready = [];
            foreach ($read as $stream) {
                if (in_array($stream, $streams, true)) {
                    $ready[] = $stream;
                } else {
                    $this->pump($stream);
                }
            }

            if ($ready !== []) {
                return $ready;
            }
        }
    }

    /**
     * The stream the relay speaks TLS on. Straight to the tunnel when it
     * listens on TCP; through a loopback pair spliced to it when it is a Unix
     * socket.
     *
     * @return resource
     */
    private function connectUpstream(int $id)
    {
        $upstream = $this->settings['upstream'];
        $timeout = max(1, $this->settings['timeout']);

        if (! isset($upstream['socket'])) {
            $server = @stream_socket_client("tcp://{$upstream['host']}:{$upstream['port']}", $errno, $error, $timeout);
            if ($server === false) {
                throw new RuntimeException("could not reach the tunnel at {$upstream['host']}:{$upstream['port']}: {$error}");
            }

            stream_set_blocking($server, false);

            return $server;
        }

        $tunnel = @stream_socket_client("unix://{$upstream['socket']}", $errno, $error, $timeout);
        if ($tunnel === false) {
            throw new RuntimeException("could not reach the tunnel at {$upstream['socket']}: {$error}");
        }

        [$near, $far] = $this->loopbackPair($timeout);

        stream_set_blocking($tunnel, false);
        stream_set_blocking($near, false);
        stream_set_blocking($far, false);
        $this->splices[$id] = [$far, $tunnel];

        return $near;
    }

    /**
     * Two connected TCP streams on the loopback, both the relay's own. The
     * listener closes as soon as the relay's connection is accepted, and an
     * accepted peer that is not that connection is refused.
     *
     * @return array{0: resource, 1: resource} the end TLS runs on, and its partner
     */
    private function loopbackPair(int $timeout): array
    {
        $listener = @stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
        if ($listener === false) {
            throw new RuntimeException("could not open a loopback pair: {$error}");
        }

        try {
            $address = (string) stream_socket_get_name($listener, false);
            $near = @stream_socket_client("tcp://{$address}", $errno, $error, $timeout);
            if ($near === false) {
                throw new RuntimeException("could not open a loopback pair: {$error}");
            }

            $far = @stream_socket_accept($listener, $timeout, $peer);
        } finally {
            fclose($listener);
        }

        if ($far === false || $peer !== stream_socket_get_name($near, false)) {
            @fclose($near);
            if ($far !== false) {
                @fclose($far);
            }

            throw new RuntimeException('another process connected to the relay\'s loopback pair first');
        }

        return [$near, $far];
    }

    /**
     * Start TLS on the server stream, verifying the certificate against the
     * database's real name. Null on success, otherwise why it failed. Done
     * without blocking, so a splice under the stream keeps moving.
     *
     * @param resource $server
     */
    private function startTls($server, float $deadline): ?string
    {
        $tls = $this->settings['tls'];
        $options = array_filter([
            'peer_name'         => $this->settings['peer_name'],
            'verify_peer'       => true,
            'verify_peer_name'  => true,
            'allow_self_signed' => false,
            'SNI_enabled'       => true,
            'cafile'            => $tls['cafile'] ?? null,
            'local_cert'        => $tls['local_cert'] ?? null,
            'local_pk'          => $tls['local_pk'] ?? null,
        ], static fn (mixed $value): bool => $value !== null);

        foreach ($options as $key => $value) {
            stream_context_set_option($server, 'ssl', $key, $value);
        }

        while (true) {
            $error = null;
            set_error_handler(static function (int $level, string $message) use (&$error): bool {
                $error = (string) preg_replace('/^stream_socket_enable_crypto\(\): /', '', $message);

                return true;
            });

            try {
                $result = stream_socket_enable_crypto(
                    $server,
                    true,
                    STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT,
                );
            } finally {
                restore_error_handler();
            }

            if ($result === true) {
                return null;
            }

            if ($result === false) {
                return $error ?? 'the TLS handshake failed';
            }

            // 0: the handshake needs more bytes. Wait for them, splicing.
            try {
                $this->service([$server], $deadline);
            } catch (RuntimeException $e) {
                return $e->getMessage();
            }
        }
    }

    /**
     * @param resource $stream
     */
    private function readPacket($stream, MysqlPacketReader $reader, float $deadline): MysqlPacket
    {
        while (! ($packet = $reader->next()) instanceof MysqlPacket) {
            $this->service([$stream], $deadline);

            $bytes = $this->drain($stream);
            if ($bytes === null) {
                throw new UnexpectedValueException('closed during the handshake');
            }

            $reader->feed($bytes);
        }

        return $packet;
    }

    /**
     * Everything readable on a non-blocking stream now, or null at its end.
     * A TLS stream is drained to empty, since select() cannot see what the
     * TLS layer has already decrypted.
     *
     * @param resource $stream
     */
    private function drain($stream): ?string
    {
        $bytes = '';

        while (true) {
            $chunk = @fread($stream, 65_536);

            if ($chunk === false) {
                return $bytes === '' ? null : $bytes;
            }

            if ($chunk === '') {
                return $bytes === '' && feof($stream) ? null : $bytes;
            }

            $bytes .= $chunk;
        }
    }

    /**
     * @param resource $client
     */
    private function refuse($client, int $sequence, string $message): void
    {
        try {
            $this->writeAll($client, MysqlHandshake::error($sequence, $message)->encode());
        } catch (RuntimeException) {
            // The client went away; nothing to tell it.
        }
    }

    /**
     * @param resource $stream
     */
    private function send($stream, MysqlPacket $packet): void
    {
        $this->writeAll($stream, $packet->encode());
    }

    /**
     * Copy what is waiting on one stream to its partner: the other side of
     * a relayed pair, or the other end of a splice.
     *
     * @param resource $from
     */
    private function pump($from): void
    {
        foreach ($this->splices as $id => [$near, $far]) {
            if ($from === $near || $from === $far) {
                $this->copy($id, $from, $from === $near ? $far : $near);

                return;
            }
        }

        foreach ($this->pairs as $id => [$client, $server]) {
            if ($from === $client || $from === $server) {
                $this->copy($id, $from, $from === $client ? $server : $client);

                return;
            }
        }
    }

    /**
     * @param resource $from
     * @param resource $to
     */
    private function copy(int $id, $from, $to): void
    {
        $bytes = $this->drain($from);

        if ($bytes === null) {
            $this->drop($id);

            return;
        }

        try {
            $this->writeAll($to, $bytes);
        } catch (RuntimeException) {
            $this->drop($id);
        }
    }

    /**
     * Write everything, moving spliced bytes while the other side is full:
     * a write into a loopback pair only drains once the splice runs.
     *
     * @param resource $stream
     */
    private function writeAll($stream, string $bytes): void
    {
        $deadline = microtime(true) + 10;

        while ($bytes !== '') {
            $written = @fwrite($stream, $bytes);

            if ($written === false) {
                throw new RuntimeException('write failed');
            }

            if ($written === 0) {
                if (microtime(true) > $deadline) {
                    throw new RuntimeException('write timed out');
                }

                $this->moveSplices();

                continue;
            }

            $bytes = substr($bytes, $written);
        }
    }

    /**
     * One pass over every splice: whatever is readable now is copied.
     */
    private function moveSplices(): void
    {
        $read = [];
        foreach ($this->splices as [$near, $far]) {
            $read[] = $near;
            $read[] = $far;
        }

        if ($read === []) {
            usleep(1_000);

            return;
        }

        $write = $except = null;
        if (@stream_select($read, $write, $except, 0, 10_000) > 0) {
            foreach ($read as $stream) {
                $this->pump($stream);
            }
        }
    }

    /**
     * Close a client's relayed pair and its splice.
     *
     * @param resource|null $client a client accepted but not yet paired
     */
    private function drop(int $id, $client = null): void
    {
        foreach ([...($this->pairs[$id] ?? []), ...($this->splices[$id] ?? [])] as $stream) {
            @fclose($stream);
        }

        if ($client !== null) {
            @fclose($client);
        }

        unset($this->pairs[$id], $this->splices[$id]);
    }

    private function closeAll(): void
    {
        foreach (array_keys($this->pairs + $this->splices) as $id) {
            $this->drop($id);
        }
    }
}

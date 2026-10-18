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
 * A client whose server fails verification gets a MySQL ERR packet naming
 * why, so PDO reports it rather than a dropped connection.
 */
final class MysqlTlsRelay
{
    /** @var array<int, array{0: resource, 1: resource}> client and server, by client id */
    private array $pairs = [];

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

        try {
            $server = $this->upgrade($client);
        } catch (Throwable $e) {
            fwrite(STDERR, "relay refused a connection: {$e->getMessage()}\n");
            @fclose($client);

            return;
        }

        stream_set_blocking($client, false);
        stream_set_blocking($server, false);
        $this->pairs[(int) $client] = [$client, $server];
    }

    /**
     * The connection phase, done synchronously: greeting, TLS to the server
     * with verification, the client's response, then authentication, with
     * each packet renumbered across the extra SSLRequest. Returns the server
     * stream once the server has sent OK.
     *
     * @param resource $client
     *
     * @return resource
     */
    private function upgrade($client)
    {
        $timeout = max(1, $this->settings['timeout']);
        stream_set_timeout($client, $timeout);

        $server = $this->connectUpstream($timeout);
        $fromServer = new MysqlPacketReader;
        $fromClient = new MysqlPacketReader;

        $greeting = $this->readPacket($server, $fromServer);
        if (! MysqlHandshake::serverSupportsSsl($greeting)) {
            $this->refuse($client, 0, "TLS to {$this->settings['peer_name']} is required, and the server does not offer it");

            throw new RuntimeException('the server does not offer TLS');
        }

        $this->send($client, MysqlHandshake::withoutSsl($greeting));
        $response = $this->readPacket($client, $fromClient);

        $this->send($server, MysqlHandshake::sslRequest($response));

        $failure = $this->startTls($server);
        if ($failure !== null) {
            $this->refuse($client, $response->sequence + 1, "TLS to {$this->settings['peer_name']} failed: {$failure}");
            @fclose($server);

            throw new RuntimeException($failure);
        }

        $this->send($server, MysqlHandshake::afterSslRequest($response));

        // Authentication: either side may speak next (fast auth success is
        // followed by OK with nothing from the client), so wait on both.
        stream_set_blocking($client, false);
        stream_set_blocking($server, false);

        while (true) {
            if (($packet = $fromServer->next()) instanceof MysqlPacket) {
                $this->send($client, $packet->withSequence($packet->sequence - 1));

                if (! MysqlHandshake::endsConnectionPhase($packet)) {
                    continue;
                }

                if ($packet->first() === 0xFF) {
                    @fclose($server);

                    throw new RuntimeException('the server refused the login');
                }

                // Commands number from 0 on both sides, so from here bytes pass
                // as they are. Anything already read past OK goes on with them.
                $this->writeAll($server, $fromClient->pending());
                $this->writeAll($client, $fromServer->pending());

                return $server;
            }

            if (($packet = $fromClient->next()) instanceof MysqlPacket) {
                $this->send($server, $packet->withSequence($packet->sequence + 1));

                continue;
            }

            $this->waitForEither($client, $server, $fromClient, $fromServer, $timeout);
        }
    }

    /**
     * Wait until either side has bytes, and read everything it has. A TLS
     * stream is drained to empty, since select() cannot see what the TLS
     * layer has already decrypted.
     *
     * @param resource $client
     * @param resource $server
     */
    private function waitForEither($client, $server, MysqlPacketReader $fromClient, MysqlPacketReader $fromServer, int $timeout): void
    {
        $read = [$client, $server];
        $write = $except = null;

        if (@stream_select($read, $write, $except, $timeout) < 1) {
            throw new RuntimeException('authentication timed out');
        }

        foreach ($read as $stream) {
            $bytes = $this->drain($stream);
            if ($bytes === null) {
                throw new RuntimeException('a side closed during authentication');
            }

            ($stream === $server ? $fromServer : $fromClient)->feed($bytes);
        }
    }

    /**
     * Everything readable on a non-blocking stream now, or null at its end.
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
     * @return resource
     */
    private function connectUpstream(int $timeout)
    {
        $upstream = $this->settings['upstream'];
        $address = isset($upstream['socket'])
            ? "unix://{$upstream['socket']}"
            : "tcp://{$upstream['host']}:{$upstream['port']}";

        $server = @stream_socket_client($address, $errno, $error, $timeout);
        if ($server === false) {
            throw new RuntimeException("could not reach the tunnel at {$address}: {$error}");
        }

        stream_set_timeout($server, $timeout);

        return $server;
    }

    /**
     * Start TLS on the server stream, verifying the certificate against the
     * database's real name. Null on success, otherwise why it failed.
     *
     * @param resource $server
     */
    private function startTls($server): ?string
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

        $error = null;
        set_error_handler(static function (int $level, string $message) use (&$error): bool {
            $error = (string) preg_replace('/^stream_socket_enable_crypto\(\): /', '', $message);

            return true;
        });

        try {
            stream_set_blocking($server, true);
            $ok = stream_socket_enable_crypto(
                $server,
                true,
                STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT,
            );
        } finally {
            restore_error_handler();
        }

        if ($ok === true) {
            return null;
        }

        return $error ?? 'the TLS handshake failed';
    }

    /**
     * @param resource $stream
     */
    private function readPacket($stream, MysqlPacketReader $reader): MysqlPacket
    {
        while (! ($packet = $reader->next()) instanceof MysqlPacket) {
            $bytes = fread($stream, 65_536);
            if ($bytes === false || $bytes === '') {
                throw new UnexpectedValueException(
                    stream_get_meta_data($stream)['timed_out'] ? 'timed out during the handshake' : 'closed during the handshake',
                );
            }

            $reader->feed($bytes);
        }

        return $packet;
    }

    /**
     * @param resource $client
     */
    private function refuse($client, int $sequence, string $message): void
    {
        @fwrite($client, MysqlHandshake::error($sequence, $message)->encode());
    }

    /**
     * @param resource $stream
     */
    private function send($stream, MysqlPacket $packet): void
    {
        $this->writeAll($stream, $packet->encode());
    }

    /**
     * Copy what is waiting on one side of a pair to the other, draining the
     * TLS layer's buffer too: select() cannot see bytes it has decrypted.
     *
     * @param resource $from
     */
    private function pump($from): void
    {
        foreach ($this->pairs as $id => [$client, $server]) {
            if ($from !== $client && $from !== $server) {
                continue;
            }

            $to = $from === $client ? $server : $client;
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

            return;
        }
    }

    /**
     * @param resource $stream
     */
    private function writeAll($stream, string $bytes): void
    {
        while ($bytes !== '') {
            $written = @fwrite($stream, $bytes);

            if ($written === false) {
                throw new RuntimeException('write failed');
            }

            if ($written === 0) {
                $read = $except = null;
                $write = [$stream];
                if (@stream_select($read, $write, $except, 5) < 1) {
                    throw new RuntimeException('write timed out');
                }

                continue;
            }

            $bytes = substr($bytes, $written);
        }
    }

    private function drop(int $id): void
    {
        [$client, $server] = $this->pairs[$id];
        @fclose($client);
        @fclose($server);
        unset($this->pairs[$id]);
    }

    private function closeAll(): void
    {
        foreach (array_keys($this->pairs) as $id) {
            $this->drop($id);
        }
    }
}

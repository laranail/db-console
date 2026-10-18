<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DBConsole\Tunnels\Phpseclib;

use Throwable;
use RuntimeException;
use phpseclib4\Net\SSH2;
use phpseclib4\Crypt\PublicKeyLoader;
use Simtabi\Laranail\DBConsole\Tunnels\HostKey;

/**
 * The phpseclib tunnel's forwarding loop, run in its own PHP process (see
 * resources/bin/phpseclib-tunnel.php). A request that holds a PDO connection
 * open is blocked inside PDO, so the bytes have to be moved by someone else.
 *
 * Protocol with the parent: settings arrive as one JSON line on stdin; the
 * pump answers `READY tcp <host> <port>` or `READY unix <path>` on stdout once
 * it is listening, or exits non-zero with the reason on stderr. It exits when
 * stdin closes, so it can never outlive the process that started it.
 *
 * Order matters for the pin: the server's host key is compared with the pin
 * after key exchange and before login(), so no credential is ever offered to
 * a server that is not the pinned one.
 */
final class ForwardPump
{
    public const int EXIT_HOST_KEY = 3;

    public const int EXIT_AUTH = 4;

    public const int EXIT_FAILED = 5;

    /** Channel ids for forwards; phpseclib's own fixed channels sit below this. */
    private const int FIRST_CHANNEL = 100;

    private ForwardingSsh2 $ssh;

    /** @var array<int, resource> client socket by channel id */
    private array $clients = [];

    private int $nextChannel = self::FIRST_CHANNEL;

    /** @var array<int, true> channels whose client has been told the far end closed */
    private array $halfClosed = [];

    /**
     * @param array{
     *     host: string, port: int, user: string, host_key: string, private_key: string,
     *     remote: array{socket: string}|array{host: string, port: int},
     *     listen: array{socket?: string},
     *     connect_timeout: int, keepalive: int
     * } $settings
     * @param resource $stdin
     * @param resource $stdout
     */
    public function __construct(private readonly array $settings, private $stdin, private $stdout) {}

    public function run(): int
    {
        try {
            $this->connect();
        } catch (PumpFailure $e) {
            fwrite(STDERR, $e->getMessage() . "\n");

            return $e->getCode();
        } catch (Throwable $e) {
            fwrite(STDERR, $e->getMessage() . "\n");

            return self::EXIT_FAILED;
        }

        $listener = $this->listen();

        try {
            $this->loop($listener);
        } catch (Throwable $e) {
            fwrite(STDERR, $e->getMessage() . "\n");

            return self::EXIT_FAILED;
        } finally {
            fclose($listener);
            if (isset($this->settings['listen']['socket'])) {
                @unlink($this->settings['listen']['socket']);
            }
        }

        return 0;
    }

    private function connect(): void
    {
        // Routes every received packet through ForwardingSsh2's log hook, the
        // only place a CHANNEL_EOF from the server can be seen. This process
        // exists only to pump, so nothing else reads the constant.
        if (! defined('NET_SSH2_LOGGING')) {
            define('NET_SSH2_LOGGING', SSH2::LOG_SIMPLE);
        }

        $pin = HostKey::parse($this->settings['host_key']);

        $this->ssh = new ForwardingSsh2($this->settings['host'], $this->settings['port'], $this->settings['connect_timeout']);
        $this->ssh->setBlockingTimeout($this->settings['connect_timeout']);

        $presented = $this->ssh->getServerPublicHostKey();
        if (! $pin->matches($presented)) {
            $seen = $presented === null ? 'none (the signature did not verify)' : HostKey::parse($presented)->fingerprint();

            throw new PumpFailure(
                "Host key verification failed: the server presented {$seen}, the pin is {$pin->fingerprint()}",
                self::EXIT_HOST_KEY,
            );
        }

        if ($this->settings['keepalive'] > 0) {
            $this->ssh->setKeepAlive($this->settings['keepalive']);
        }

        if (! $this->ssh->login($this->settings['user'], PublicKeyLoader::loadPrivateKey($this->settings['private_key']))) {
            throw new PumpFailure("Permission denied (publickey) for user '{$this->settings['user']}'", self::EXIT_AUTH);
        }
    }

    /**
     * @return resource
     */
    private function listen()
    {
        $socket = $this->settings['listen']['socket'] ?? null;

        $umask = umask(0o177);
        try {
            $listener = $socket !== null
                ? @stream_socket_server("unix://{$socket}", $errno, $error)
                : @stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
        } finally {
            umask($umask);
        }

        if ($listener === false) {
            throw new RuntimeException("could not listen: {$error}");
        }

        if ($socket !== null) {
            chmod($socket, 0o600);
            fwrite($this->stdout, "READY unix {$socket}\n");
        } else {
            $name = (string) stream_socket_get_name($listener, false);
            $port = substr($name, strrpos($name, ':') + 1);
            fwrite($this->stdout, "READY tcp 127.0.0.1 {$port}\n");
        }

        fflush($this->stdout);

        return $listener;
    }

    /**
     * @param resource $listener
     */
    private function loop($listener): void
    {
        while (true) {
            $read = [$listener, $this->stdin, ...array_values($this->clients)];
            if (is_resource($this->ssh->fsock)) {
                $read[] = $this->ssh->fsock;
            }

            $write = $except = null;
            // A short timeout: phpseclib may hold decrypted data in its own
            // buffers, which select() cannot see, so every channel is polled
            // on each pass anyway.
            @stream_select($read, $write, $except, 0, 20_000);

            foreach ($read as $stream) {
                if ($stream === $this->stdin) {
                    if (fgets($this->stdin) === false || feof($this->stdin)) {
                        $this->closeAll();

                        return;
                    }
                } elseif ($stream === $listener) {
                    $this->accept($listener);
                } elseif (($channel = array_search($stream, $this->clients, true)) !== false) {
                    $this->fromClient($channel);
                }
            }

            foreach (array_keys($this->clients) as $channel) {
                $this->toClient($channel);
            }

            if (! $this->ssh->isConnected()) {
                throw new RuntimeException('the SSH connection closed');
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

        $channel = $this->nextChannel++;
        $remote = $this->settings['remote'];

        try {
            if (isset($remote['socket'])) {
                $this->ssh->openSocket($channel, $remote['socket']);
            } else {
                $this->ssh->openTcp($channel, $remote['host'], $remote['port']);
            }
        } catch (Throwable $e) {
            fwrite(STDERR, "forward refused: {$e->getMessage()}\n");
            fclose($client);

            return;
        }

        stream_set_blocking($client, true);
        $this->clients[$channel] = $client;
    }

    private function fromClient(int $channel): void
    {
        $data = fread($this->clients[$channel], 32_768);

        if ($data === false || ($data === '' && feof($this->clients[$channel]))) {
            $this->drop($channel);

            return;
        }

        if ($data !== '') {
            $this->ssh->writeForward($channel, $data);
        }
    }

    private function toClient(int $channel): void
    {
        while (isset($this->clients[$channel])) {
            $data = $this->ssh->readForward($channel);

            if ($data === null) {
                // The database closed its side (it ended the session): say so
                // to the client by shutting our write side, as OpenSSH's own
                // forwarding does. Without it libpq, having read a FATAL error,
                // waits for an end of stream that never comes.
                if ($this->ssh->remoteFinished($channel) && ! isset($this->halfClosed[$channel])) {
                    stream_socket_shutdown($this->clients[$channel], STREAM_SHUT_WR);
                    $this->halfClosed[$channel] = true;
                }

                return;
            }

            if ($data === false) {
                fclose($this->clients[$channel]);
                unset($this->clients[$channel], $this->halfClosed[$channel]);

                return;
            }

            fwrite($this->clients[$channel], $data);
        }
    }

    private function drop(int $channel): void
    {
        fclose($this->clients[$channel]);
        unset($this->clients[$channel], $this->halfClosed[$channel]);

        $this->ssh->closeForward($channel);
    }

    private function closeAll(): void
    {
        foreach (array_keys($this->clients) as $channel) {
            $this->drop($channel);
        }

        $this->ssh->disconnect();
    }
}

<?php

declare(strict_types=1);

/*
 * Stands in for an SSH tunnel whose local end is a Unix socket: every
 * connection to <socket> is piped to 127.0.0.1:<port>, both ways, as
 * `ssh -L <socket>:host:port` would.
 *
 * Usage: php unix-to-tcp-forwarder.php <socket> <port>
 */

[, $socket, $port] = $argv;

@unlink($socket);
$listener = stream_socket_server("unix://{$socket}", $errno, $error);
if ($listener === false) {
    fwrite(STDERR, "listen: {$error}\n");
    exit(2);
}
fwrite(STDOUT, "READY\n");

/** @var list<array{0: resource, 1: resource}> $pipes */
$pipes = [];

while (true) {
    $read = [$listener];
    foreach ($pipes as [$a, $b]) {
        $read[] = $a;
        $read[] = $b;
    }

    $write = $except = null;
    if (@stream_select($read, $write, $except, 30) < 1) {
        exit(0);
    }

    foreach ($read as $stream) {
        if ($stream === $listener) {
            $local = stream_socket_accept($listener);
            $remote = stream_socket_client("tcp://127.0.0.1:{$port}");
            stream_set_blocking($local, false);
            stream_set_blocking($remote, false);
            $pipes[] = [$local, $remote];

            continue;
        }

        foreach ($pipes as $i => [$a, $b]) {
            if ($stream !== $a && $stream !== $b) {
                continue;
            }

            $bytes = fread($stream, 65536);
            if ($bytes === false || ($bytes === '' && feof($stream))) {
                fclose($a);
                fclose($b);
                unset($pipes[$i]);

                break;
            }

            stream_set_blocking($stream === $a ? $b : $a, true);
            fwrite($stream === $a ? $b : $a, $bytes);
            stream_set_blocking($stream === $a ? $b : $a, false);

            break;
        }
    }
}

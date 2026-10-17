<?php

declare(strict_types=1);

/*
 * The phpseclib tunnel's child process. Started by PhpseclibTunnelDriver as
 * `php phpseclib-tunnel.php <vendor/autoload.php>`; settings, including the
 * private key, arrive on stdin and never on the command line, where any local
 * user could read them from the process list.
 */

use Simtabi\Laranail\DBConsole\Tunnels\Phpseclib\ForwardPump;

if (PHP_SAPI !== 'cli' || ! isset($argv[1]) || ! is_file($argv[1])) {
    fwrite(STDERR, "usage: php phpseclib-tunnel.php <vendor/autoload.php>\n");
    exit(2);
}

require $argv[1];

$line = fgets(STDIN);
$settings = is_string($line) ? json_decode($line, true) : null;
if (! is_array($settings)) {
    fwrite(STDERR, "expected the tunnel settings as one JSON line on stdin\n");
    exit(2);
}

exit(new ForwardPump($settings, STDIN, STDOUT)->run());

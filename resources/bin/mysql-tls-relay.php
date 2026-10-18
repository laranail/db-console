<?php

declare(strict_types=1);

/*
 * The MySQL TLS relay's child process. Started by WorkerProcess as
 * `php mysql-tls-relay.php <vendor/autoload.php>`; settings arrive on stdin,
 * and the process exits when its stdin closes.
 */

use Simtabi\Laranail\DBConsole\Tunnels\Relay\MysqlTlsRelay;

if (PHP_SAPI !== 'cli' || ! isset($argv[1]) || ! is_file($argv[1])) {
    fwrite(STDERR, "usage: php mysql-tls-relay.php <vendor/autoload.php>\n");
    exit(2);
}

require $argv[1];

$line = fgets(STDIN);
$settings = is_string($line) ? json_decode($line, true) : null;
if (! is_array($settings)) {
    fwrite(STDERR, "expected the relay settings as one JSON line on stdin\n");
    exit(2);
}

exit(new MysqlTlsRelay($settings, STDIN, STDOUT)->run());

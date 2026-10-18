<?php

declare(strict_types=1);

use Illuminate\Database\Connection;
use Illuminate\Support\Facades\Storage;
use Simtabi\Laranail\DBConsole\Domain\DbName;
use Simtabi\Laranail\DBConsole\Enums\TlsStatus;
use Simtabi\Laranail\DBConsole\Doctor\DoctorService;
use Simtabi\Laranail\DBConsole\Encryption\TlsChecker;
use Simtabi\Laranail\DBConsole\Servers\ServerRegistry;
use Simtabi\Laranail\DBConsole\Exceptions\TunnelFailed;
use Simtabi\Laranail\DBConsole\Backup\DbToolsBackupService;
use Simtabi\Laranail\DBConsole\Exceptions\ServerUnreachable;

/*
 * Against a real SSH server: the `live-ssh` job in .github/workflows/tests.yml
 * runs an OpenSSH server in a container on the same network as PostgreSQL 18
 * and MySQL 8.4, shares both servers' socket directories with it, sets the
 * env read below and runs this group with --fail-on-skipped. Everywhere else
 * the group skips.
 *
 * From the SSH server, the databases are `postgres:5432` and `mysql:3306`,
 * and their sockets sit where each image puts them.
 */

uses()->group('live-ssh');

const LIVE_PG_SOCKET = '/var/run/postgresql/.s.PGSQL.5432';
const LIVE_MYSQL_SOCKET = '/var/run/mysqld/mysqld.sock';

beforeEach(function (): void {
    if (getenv('DB_CONSOLE_LIVE_SSH_PORT') === false) {
        $this->markTestSkipped('needs DB_CONSOLE_LIVE_SSH_PORT (the live-ssh CI job)');
    }
});

afterEach(function (): void {
    if (app()->resolved(ServerRegistry::class)) {
        app(ServerRegistry::class)->closeTunnels();
    }
});

/**
 * Register a server reached through the CI SSH server.
 *
 * @param array<string, mixed> $ssh
 * @param array<string, mixed> $tls
 */
function liveTunnel(string $engine, string $driver, array $ssh = [], array $tls = ['enabled' => false], ?string $host = null): string
{
    $name = "ssh_{$engine}_{$driver}" . ($host !== null ? '_' . str_replace('-', '_', $host) : '');

    config()->set("database.connections.{$name}", $engine === 'pgsql'
        ? ['driver' => 'pgsql', 'host' => $host ?? 'postgres', 'port' => 5432, 'database' => 'db_console_test', 'username' => 'db_console', 'password' => 'secret']
        : ['driver' => 'mysql', 'host' => $host ?? 'mysql', 'port' => 3306, 'database' => 'db_console_test', 'username' => 'root', 'password' => 'secret']);

    config()->set("laranail.db-console.servers.{$name}", [
        'engine'     => $engine,
        'connection' => $name,
        'tls'        => $tls,
        'ssh'        => [
            'enabled'          => true,
            'driver'           => $driver,
            'host'             => '127.0.0.1',
            'port'             => (int) getenv('DB_CONSOLE_LIVE_SSH_PORT'),
            'user'             => (string) getenv('DB_CONSOLE_LIVE_SSH_USER'),
            'key'              => (string) getenv('DB_CONSOLE_LIVE_SSH_KEY'),
            'host_key'         => (string) getenv('DB_CONSOLE_LIVE_SSH_HOST_KEY'),
            'connect_timeout'  => 15,
            'socket_directory' => (string) getenv('RUNNER_TEMP') ?: sys_get_temp_dir(),
            ...$ssh,
        ],
    ]);

    return $name;
}

function liveScalar(string $server, string $sql): mixed
{
    $row = (array) app(ServerRegistry::class)->connection($server)->underlying()->selectOne($sql);

    return reset($row);
}

dataset('drivers', ['openssh', 'phpseclib']);

it('reaches Postgres and MySQL over a forwarded port', function (string $driver): void {
    $pg = liveTunnel('pgsql', $driver);
    $mysql = liveTunnel('mysql', $driver);

    expect(liveScalar($pg, 'SELECT current_database()'))->toBe('db_console_test')
        ->and(liveScalar($mysql, 'SELECT DATABASE()'))->toBe('db_console_test')
        ->and(app(ServerRegistry::class)->connection($pg)->underlying()->getConfig('host'))->toBe('127.0.0.1');
})->with('drivers');

// The scoped-connection path: a second session through the same tunnel while
// the first is open, which the phpseclib pump has to multiplex.
it('carries two sessions at once', function (string $driver): void {
    $server = liveTunnel('pgsql', $driver);
    $admin = app(ServerRegistry::class)->connection($server)->underlying();
    assert($admin instanceof Connection);

    $second = app('db')->connectUsing("{$server}_second", $admin->getConfig(), true);

    $first = $admin->selectOne('SELECT pg_backend_pid() AS pid')->pid;
    $other = $second->selectOne('SELECT pg_backend_pid() AS pid')->pid;

    expect($other)->not->toBe($first)
        ->and($admin->selectOne('SELECT 41 + 1 AS answer')->answer)->toBe(42);

    app('db')->purge("{$server}_second");
})->with('drivers');

it('forwards to a Unix socket on the SSH host', function (string $driver): void {
    $pg = liveTunnel('pgsql', $driver, ['remote_socket' => LIVE_PG_SOCKET]);
    $mysql = liveTunnel('mysql', $driver, ['remote_socket' => LIVE_MYSQL_SOCKET]);

    // Over a socket the server sees no client address at all.
    expect(liveScalar($pg, 'SELECT inet_client_addr() IS NULL'))->toBeTrue()
        ->and(liveScalar($mysql, 'SELECT DATABASE()'))->toBe('db_console_test')
        ->and(app(TlsChecker::class)->isLocal($pg))->toBeTrue();
})->with('drivers');

it('listens on a 0600 socket in a 0700 directory instead of a port', function (string $driver): void {
    $server = liveTunnel('mysql', $driver, ['local' => 'socket']);

    expect(liveScalar($server, 'SELECT DATABASE()'))->toBe('db_console_test');

    $socket = (string) app(ServerRegistry::class)->connection($server)->underlying()->getConfig('unix_socket');

    expect(filetype($socket))->toBe('socket')
        ->and(fileperms($socket) & 0o077)->toBe(0)
        ->and(fileperms(dirname($socket)) & 0o777)->toBe(0o700);

    app(ServerRegistry::class)->closeTunnels();

    expect(file_exists(dirname($socket)))->toBeFalse();
})->with('drivers');

it('refuses a server whose host key is not the pin', function (string $driver): void {
    $server = liveTunnel('pgsql', $driver, ['host_key' => (string) getenv('DB_CONSOLE_LIVE_SSH_WRONG_HOST_KEY')]);

    expect(fn () => app(ServerRegistry::class)->resolve($server))
        ->toThrow(TunnelFailed::class, 'Host key verification failed');
})->with('drivers');

// TLS from the tls block still applies, from PHP to the database, inside the
// tunnel: the MySQL session measures as encrypted.
it('keeps TLS end to end through the tunnel', function (string $driver): void {
    $ca = (string) getenv('DB_CONSOLE_LIVE_MYSQL_CA');
    expect(is_file($ca))->toBeTrue("DB_CONSOLE_LIVE_MYSQL_CA must name the server's CA file, got '{$ca}'");

    $server = liveTunnel('mysql', $driver, tls: ['enabled' => true, 'verify' => false, 'ca' => $ca]);

    expect(app(TlsChecker::class)->measure($server))->toBeTrue();
})->with('drivers');

// The image runs Postgres without SSL, so a verify-full session is refused,
// and how it is refused proves the hostaddr route: libpq reached the server
// through the forward (it says the server lacks SSL) instead of failing to
// resolve `postgres`, a name only the SSH host can resolve.
it('dials the forward and not the certificate name under verify-full', function (): void {
    $server = liveTunnel('pgsql', 'openssh', tls: ['enabled' => true, 'verify' => true]);

    expect(app(ServerRegistry::class)->connection($server)->underlying()->getConfig('host'))
        ->toBe('postgres;hostaddr=127.0.0.1');

    try {
        app(ServerRegistry::class)->ensureReachable($server);
        $this->fail('a plaintext server accepted sslmode=verify-full');
    } catch (ServerUnreachable $e) {
        $detail = (string) $e->getPrevious()?->getMessage();

        expect($detail)->toContain('SSL')->not->toContain('translate host name');
    }
});

it('passes doctor through the tunnel', function (string $driver): void {
    $server = liveTunnel('pgsql', $driver);

    $checks = [];
    foreach (app(DoctorService::class)->checkServer($server) as $finding) {
        $checks[$finding->check] = $finding->severity->value;
    }

    expect($checks)->toMatchArray([
        "server:{$server}:ssh-driver"   => 'info',
        "server:{$server}:ssh-host-key" => 'info',
        "server:{$server}:ssh-tunnel"   => 'info',
        "server:{$server}:reachable"    => 'info',
    ]);
})->with('drivers');

it('stops the forwarder when the tunnels close', function (string $driver): void {
    $server = liveTunnel('pgsql', $driver);
    liveScalar($server, 'SELECT 1');
    $port = (int) app(ServerRegistry::class)->connection($server)->underlying()->getConfig('port');

    app(ServerRegistry::class)->closeTunnels();

    expect(@fsockopen('127.0.0.1', $port, $errno, $error, 1))->toBeFalse();
})->with('drivers');

/*
 * A second PostgreSQL, `pgtls`, runs with TLS on and a certificate for the
 * name `pgtls` only, signed by a CA the job generates. It also answers to
 * `pgtls-alias` on the network, a name its certificate does not carry. From
 * the runner neither name resolves: every byte goes through the tunnel.
 */

/**
 * @param array<string, mixed> $ssh
 */
function liveVerifiedTunnel(string $driver, string $host, array $ssh = []): string
{
    $ca = (string) getenv('DB_CONSOLE_LIVE_PGTLS_CA');
    expect(is_file($ca))->toBeTrue("DB_CONSOLE_LIVE_PGTLS_CA must name the CA that signed pgtls's certificate, got '{$ca}'");

    return liveTunnel('pgsql', $driver, $ssh, ['enabled' => true, 'verify' => true, 'ca' => $ca], $host);
}

it('verifies the certificate against the real name through the tunnel', function (string $driver): void {
    $server = liveVerifiedTunnel($driver, 'pgtls');

    expect(app(TlsChecker::class)->status($server))->toBe(TlsStatus::RequiredOk)
        ->and(app(ServerRegistry::class)->connection($server)->underlying()->getConfig('sslmode'))->toBe('verify-full')
        ->and(liveScalar($server, 'SELECT ssl FROM pg_stat_ssl WHERE pid = pg_backend_pid()'))->toBeTrue();
})->with('drivers');

it('refuses a certificate that does not name the host', function (string $driver): void {
    $server = liveVerifiedTunnel($driver, 'pgtls-alias');

    try {
        app(ServerRegistry::class)->ensureReachable($server);
        $this->fail('a certificate for pgtls was accepted for pgtls-alias');
    } catch (ServerUnreachable $e) {
        expect((string) $e->getPrevious()?->getMessage())->toContain('does not match host name');
    }
})->with('drivers');

// db-tools 0.1.7 hands hostaddr to pg_dump as PGHOSTADDR. Before it, this
// backup failed, and a failed backup stops the drop it was taken for.
it('backs up through the tunnel under verify-full', function (string $driver): void {
    $bin = (string) getenv('DB_CONSOLE_LIVE_PG_BIN');
    expect(is_executable("{$bin}/pg_dump"))->toBeTrue("DB_CONSOLE_LIVE_PG_BIN must hold a PostgreSQL 18 pg_dump, got '{$bin}'");

    foreach (['pg_dump', 'pg_restore', 'psql'] as $tool) {
        config()->set("laranail.db-tools.backup.binaries.{$tool}", "{$bin}/{$tool}");
    }
    config()->set('filesystems.disks.local', ['driver' => 'local', 'root' => sys_get_temp_dir() . "/db-console-live-ssh-{$driver}"]);

    $server = liveVerifiedTunnel($driver, 'pgtls');
    liveScalar($server, 'CREATE TABLE IF NOT EXISTS tunnelled_backup_marker (id int)');

    $path = app(DbToolsBackupService::class)->snapshot($server, new DbName('db_console_test'));

    expect($path)->toBeString()
        ->and(Storage::disk('local')->size((string) $path))->toBeGreaterThan(0);
})->with('drivers');

// phpseclib raises its read timeout to 5 s when it handles one channel's
// CHANNEL_CLOSE while reading for another. The server ends session B through
// idle_session_timeout while A sits idle, so B's close arrives while the pump
// polls A, the first channel. A's next query must not wait out that timeout.
it('keeps other sessions responsive when the server closes one', function (string $driver): void {
    $server = liveTunnel('pgsql', $driver);
    $a = app(ServerRegistry::class)->connection($server)->underlying();
    assert($a instanceof Connection);
    $a->selectOne('SELECT 1');

    $b = app('db')->connectUsing("{$server}_idle", $a->getConfig(), true);
    $b->statement("SET idle_session_timeout = '300ms'");

    usleep(1_000_000);

    $start = microtime(true);
    $answer = $a->selectOne('SELECT 41 + 1 AS answer')->answer;
    $elapsed = microtime(true) - $start;

    app('db')->purge("{$server}_idle");

    expect($answer)->toBe(42)
        ->and($elapsed)->toBeLessThan(1.0, sprintf('session A waited %.2fs after the server closed session B', $elapsed));
})->with('drivers');

<?php

declare(strict_types=1);

use Simtabi\Laranail\DBConsole\Secrets\Secret;
use Simtabi\Laranail\DBConsole\Tunnels\Endpoint;
use Simtabi\Laranail\DBConsole\Secrets\SecretVault;
use Simtabi\Laranail\DBConsole\Encryption\TlsChecker;
use Simtabi\Laranail\DBConsole\Tunnels\TunnelManager;
use Simtabi\Laranail\DBConsole\Servers\ServerRegistry;
use Simtabi\Laranail\DBConsole\Exceptions\TunnelFailed;
use Simtabi\Laranail\DBConsole\Servers\ServerDefinition;
use Simtabi\Laranail\DBConsole\Tunnels\Contracts\Tunnel;
use Simtabi\Laranail\DBConsole\Tunnels\PrivateDirectory;
use Simtabi\Laranail\DBConsole\Tests\Fixtures\TunnelKeys;
use Simtabi\Laranail\DBConsole\Exceptions\ServerMisconfigured;
use Simtabi\Laranail\DBConsole\Tests\Fixtures\FakeTunnelDriver;
use Simtabi\Laranail\DBConsole\Tunnels\Contracts\TerminatesTls;
use Simtabi\Laranail\DBConsole\Tunnels\Contracts\TlsRelayLauncher;
use Simtabi\Laranail\DBConsole\Tests\Fixtures\FakeTlsRelayLauncher;
use Simtabi\Laranail\DBConsole\Tunnels\Drivers\OpenSshTunnelDriver;

/**
 * @param array<string, mixed> $ssh
 * @param array<string, mixed> $tls
 * @param array<string, mixed> $connection
 */
function tunnelledServer(array $ssh = [], array $tls = ['enabled' => false], array $connection = []): void
{
    config()->set('database.connections.admin_tunnelled', [
        'driver'   => 'mysql', 'host' => 'db.internal', 'port' => 3306, 'database' => 'defaultdb',
        'username' => 'admin', 'password' => 'secret', 'unix_socket' => '', ...$connection,
    ]);
    config()->set('laranail.db-console.servers.behind-bastion', [
        'engine'     => ($connection['driver'] ?? 'mysql') === 'pgsql' ? 'pgsql' : 'mysql',
        'connection' => 'admin_tunnelled',
        'tls'        => $tls,
        'ssh'        => [
            'enabled' => true, 'host' => 'bastion.example.com', 'user' => 'tunnel',
            'key'     => __FILE__, 'host_key' => TunnelKeys::PINNED, ...$ssh,
        ],
    ]);
}

beforeEach(function (): void {
    $this->driver = new FakeTunnelDriver;
    app()->instance(OpenSshTunnelDriver::class, $this->driver);

    $this->relays = new FakeTlsRelayLauncher;
    app()->instance(TlsRelayLauncher::class, $this->relays);
});

it('opens the tunnel on first use and points the admin connection at it', function (): void {
    tunnelledServer();

    $connection = app(ServerRegistry::class)->connection('behind-bastion')->underlying();

    expect($this->driver->opened)->toHaveCount(1)
        ->and($this->driver->opened[0]->remote->describe())->toBe('db.internal:3306')
        ->and($connection->getConfig('host'))->toBe('127.0.0.1')
        ->and($connection->getConfig('port'))->toBe(40000)
        ->and($connection->getConfig('username'))->toBe('admin');
});

it('opens one tunnel per server, not one per resolve', function (): void {
    tunnelledServer();
    $registry = app(ServerRegistry::class);

    $registry->resolve('behind-bastion');
    $registry->resolve('behind-bastion');

    expect($this->driver->opened)->toHaveCount(1);
});

it('reopens a tunnel that died, to the real target and not its own dead end', function (): void {
    tunnelledServer();
    $registry = app(ServerRegistry::class);
    $registry->resolve('behind-bastion');

    $this->driver->tunnels[0]->alive = false;
    $connection = $registry->connection('behind-bastion')->underlying();

    expect($this->driver->opened)->toHaveCount(2)
        ->and($this->driver->opened[1]->remote->describe())->toBe('db.internal:3306')
        ->and($connection->getConfig('port'))->toBe(40001);
});

it('closes the tunnels and restores the connection when the app terminates', function (): void {
    tunnelledServer();
    $before = config('database.connections.admin_tunnelled');
    app(ServerRegistry::class)->resolve('behind-bastion');

    app()->terminate();

    expect($this->driver->tunnels[0]->closed)->toBeTrue()
        ->and(config('database.connections.admin_tunnelled'))->toBe($before);
});

it('writes a key from the secrets driver as a 0600 file in a 0700 directory, and removes both', function (): void {
    app()->instance(SecretVault::class, new class implements SecretVault
    {
        public function store(string $ref, Secret $secret): void {}

        public function reveal(string $ref): Secret
        {
            // Not a key: the test only follows the bytes to the file.
            return new Secret("fake-key-material-for:{$ref}");
        }

        public function forget(string $ref): void {}

        public function rotate(string $ref, Secret $new): void {}

        public function driver(): string
        {
            return 'fake';
        }
    });
    tunnelledServer(['key' => null, 'key_secret' => 'ssh/bastion']);

    app(ServerRegistry::class)->resolve('behind-bastion');
    $spec = $this->driver->opened[0];

    expect(fileperms($spec->directory->path) & 0o777)->toBe(0o700)
        ->and(fileperms($spec->privateKeyPath) & 0o777)->toBe(0o600)
        ->and(dirname($spec->privateKeyPath))->toBe($spec->directory->path)
        ->and(file_get_contents($spec->privateKeyPath))->toBe("fake-key-material-for:ssh/bastion\n");

    app(ServerRegistry::class)->closeTunnels();

    expect(file_exists($spec->privateKeyPath))->toBeFalse()
        ->and(is_dir($spec->directory->path))->toBeFalse();
});

it('refuses a tunnel without a pinned host key before opening anything', function (): void {
    tunnelledServer(['host_key' => null]);

    expect(fn () => app(ServerRegistry::class)->resolve('behind-bastion'))
        ->toThrow(ServerMisconfigured::class, 'never trusts a host key on first use')
        ->and($this->driver->opened)->toBe([]);
});

it('surfaces a tunnel that fails and leaves the connection as configured', function (): void {
    tunnelledServer();
    $before = config('database.connections.admin_tunnelled');
    $this->driver->failWith = 'Host key verification failed';

    expect(fn () => app(ServerRegistry::class)->resolve('behind-bastion'))
        ->toThrow(TunnelFailed::class, 'Host key verification failed')
        ->and(config('database.connections.admin_tunnelled'))->toBe($before);
});

describe('sockets', function (): void {
    it('puts a MySQL local socket end in the tunnel directory', function (): void {
        tunnelledServer(['local' => 'socket']);

        $connection = app(ServerRegistry::class)->connection('behind-bastion')->underlying();
        $socket = (string) $connection->getConfig('unix_socket');

        expect($socket)->toBe($this->driver->opened[0]->directory->path . '/db.sock')
            ->and(fileperms(dirname($socket)) & 0o777)->toBe(0o700);
    });

    it('names a Postgres local socket the way libpq looks for it', function (): void {
        tunnelledServer(['local' => 'socket'], connection: ['driver' => 'pgsql', 'port' => 5432]);

        $connection = app(ServerRegistry::class)->connection('behind-bastion')->underlying();

        expect($connection->getConfig('host'))->toBe($this->driver->opened[0]->directory->path)
            ->and($this->driver->opened[0]->localSocket)->toEndWith('/.s.PGSQL.5432');
    });

    // The guard: TLS cannot cross a Unix socket, so this would quietly send
    // the SSH-host-to-database leg in plaintext while config says TLS.
    it('refuses a socket local end that would drop required TLS on the onward hop', function (array $tls, array $connection): void {
        tunnelledServer(['local' => 'socket'], $tls, $connection);

        expect(fn () => app(ServerRegistry::class)->resolve('behind-bastion'))
            ->toThrow(ServerMisconfigured::class, 'a Unix socket cannot carry TLS')
            ->and($this->driver->opened)->toBe([]);
    })->with([
        // Postgres verifies through hostaddr, which needs a TCP local end.
        'postgres, verified' => [['enabled' => true, 'verify' => true], ['driver' => 'pgsql', 'port' => 5432]],
        // Unverified MySQL TLS is the client's own, and it cannot run on a socket.
        'mysql, not verified' => [['enabled' => true, 'verify' => false], []],
    ]);

    it('allows a socket local end when the onward hop stays on the SSH host', function (): void {
        tunnelledServer(['local' => 'socket', 'remote_socket' => '/var/run/mysqld/mysqld.sock'], ['enabled' => true]);

        app(ServerRegistry::class)->resolve('behind-bastion');

        expect($this->driver->opened[0]->remote->describe())->toBe('unix:/var/run/mysqld/mysqld.sock');
    });
});

describe('TLS through the tunnel', function (): void {
    it('still applies the tls block, and Postgres verifies the real name', function (): void {
        tunnelledServer(
            tls: ['enabled' => true, 'verify' => true, 'ca' => '/certs/ca.crt'],
            connection: ['driver' => 'pgsql', 'port' => 5432],
        );

        $connection = app(ServerRegistry::class)->connection('behind-bastion')->underlying();

        expect($connection->getConfig('sslmode'))->toBe('verify-full')
            ->and($connection->getConfig('sslrootcert'))->toBe('/certs/ca.crt')
            ->and($connection->getConfig('host'))->toBe('db.internal;hostaddr=127.0.0.1');
    });

    // The connection's host is now 127.0.0.1. Reading locality from it would
    // exempt every tunnelled server from the TLS check.
    it('does not treat a tunnelled server as local because it dials the loopback', function (): void {
        tunnelledServer();
        app(ServerRegistry::class)->resolve('behind-bastion');

        expect(app(TlsChecker::class)->isLocal('behind-bastion'))->toBeFalse();
    });

    it('treats a tunnel to a socket or loopback on the SSH host as local', function (array $ssh): void {
        tunnelledServer($ssh);

        expect(app(TlsChecker::class)->isLocal('behind-bastion'))->toBeTrue();
    })->with([
        'remote socket'   => [['remote_socket' => '/var/run/mysqld/mysqld.sock']],
        'remote loopback' => [['remote_host' => '127.0.0.1']],
    ]);
});

it('treats a direct Postgres socket connection as local', function (): void {
    config()->set('database.connections.admin_socket', ['driver' => 'pgsql', 'host' => '/var/run/postgresql', 'database' => 'postgres']);
    config()->set('laranail.db-console.servers.on-box', ['engine' => 'pgsql', 'connection' => 'admin_socket']);

    expect(app(TlsChecker::class)->isLocal('on-box'))->toBeTrue();
});

it('is a singleton, so one process keeps one set of tunnels', function (): void {
    expect(app(TunnelManager::class))->toBe(app(TunnelManager::class));
});

// doctor compares the port the server says it listens on with the port the
// admin connection uses. Through a tunnel the connection's port is the
// tunnel's local end, which never matches, so the comparison has to use the
// port the tunnel forwards to.
it('reports the port the database is reached on, not the tunnel\'s local end', function (): void {
    tunnelledServer(connection: ['driver' => 'pgsql', 'port' => 6432]);
    $registry = app(ServerRegistry::class);

    expect($registry->connection('behind-bastion')->underlying()->getConfig('port'))->toBe(40000)
        ->and($registry->databasePort('behind-bastion'))->toBe(6432);
});

it('reports the remote port the ssh block names over the connection\'s', function (): void {
    tunnelledServer(ssh: ['remote_host' => 'pool.internal', 'remote_port' => 25061], connection: ['driver' => 'pgsql', 'port' => 5432]);

    expect(app(ServerRegistry::class)->databasePort('behind-bastion'))->toBe(25061);
});

it('reports no port for a tunnel to a socket', function (): void {
    tunnelledServer(ssh: ['remote_socket' => '/var/run/postgresql/.s.PGSQL.5432'], connection: ['driver' => 'pgsql']);

    expect(app(ServerRegistry::class)->databasePort('behind-bastion'))->toBeNull();
});

it('reports the configured port of a server without a tunnel', function (): void {
    config()->set('database.connections.direct_pg', ['driver' => 'pgsql', 'host' => 'db.internal', 'port' => 6432, 'database' => 'defaultdb']);
    config()->set('laranail.db-console.servers.direct', ['engine' => 'pgsql', 'connection' => 'direct_pg', 'tls' => ['enabled' => false]]);

    expect(app(ServerRegistry::class)->databasePort('direct'))->toBe(6432);
});

it('reports the host the database is reached on, not the tunnel\'s local end', function (): void {
    tunnelledServer(connection: ['host' => 'db-shop.ondigitalocean.com']);
    $registry = app(ServerRegistry::class);

    expect($registry->connection('behind-bastion')->underlying()->getConfig('host'))->toBe('127.0.0.1')
        ->and($registry->databaseHost('behind-bastion'))->toBe('db-shop.ondigitalocean.com');
});

// A read/write split names several hosts; the hosting check used the first.
it('reports the first host of a connection that lists several', function (): void {
    config()->set('database.connections.split_pg', ['driver' => 'pgsql', 'host' => ['db-a.ondigitalocean.com', 'db-b.ondigitalocean.com'], 'port' => 25060]);
    config()->set('laranail.db-console.servers.split', ['engine' => 'pgsql', 'connection' => 'split_pg', 'tls' => ['enabled' => false]]);

    expect(app(ServerRegistry::class)->databaseHost('split'))->toBe('db-a.ondigitalocean.com');
});

describe('the MySQL TLS relay', function (): void {
    // pdo_mysql verifies against the host it dials, 127.0.0.1 through a
    // tunnel. The relay verifies the real name and the client talks to it.
    it('puts the relay in front of a tunnel to a verified MySQL', function (): void {
        tunnelledServer(tls: ['enabled' => true, 'verify' => true, 'ca' => '/certs/ca.pem']);

        $connection = app(ServerRegistry::class)->connection('behind-bastion')->underlying();

        expect($this->relays->launched)->toBe([['server' => 'behind-bastion', 'upstream' => '127.0.0.1:40000', 'name' => 'db.internal']])
            ->and($connection->getConfig('unix_socket'))->toBe($this->driver->opened[0]->directory->path . '/mysql-tls.sock')
            ->and((array) $connection->getConfig('options'))->toBe([]);
    });

    // PHP starts TLS only on tcp:// streams, so the relay cannot reach a
    // tunnel whose local end is a Unix socket.
    it('refuses a socket local end for a relayed server, and says why', function (): void {
        tunnelledServer(['local' => 'socket'], ['enabled' => true, 'verify' => true]);

        expect(fn () => app(ServerRegistry::class)->resolve('behind-bastion'))
            ->toThrow(ServerMisconfigured::class, 'PHP cannot start TLS on a Unix socket')
            ->and($this->driver->opened)->toBe([])
            ->and($this->relays->launched)->toBe([]);
    });

    it('leaves TLS to the client where it can verify, or where nothing needs verifying', function (array $ssh, array $tls, array $connection): void {
        tunnelledServer($ssh, $tls, $connection);

        app(ServerRegistry::class)->resolve('behind-bastion');

        expect($this->relays->launched)->toBe([]);
    })->with([
        'mysql, unverified TLS'    => [[], ['enabled' => true, 'verify' => false], []],
        'mysql, TLS off'           => [[], ['enabled' => false], []],
        'mysql to a remote socket' => [['remote_socket' => '/var/run/mysqld/mysqld.sock'], ['enabled' => true, 'verify' => true], []],
        'mysql to the SSH host'    => [['remote_host' => '127.0.0.1'], ['enabled' => true, 'verify' => true], []],
        'postgres, verified'       => [[], ['enabled' => true, 'verify' => true], ['driver' => 'pgsql', 'port' => 5432]],
    ]);

    it('closes the tunnel when its relay cannot start', function (): void {
        app()->instance(TlsRelayLauncher::class, new class implements TlsRelayLauncher
        {
            public function launch(ServerDefinition $definition, Tunnel $tunnel, Endpoint $remote, PrivateDirectory $directory, int $timeout): Tunnel&TerminatesTls
            {
                throw new RuntimeException('no PHP CLI binary was found');
            }
        });
        tunnelledServer(tls: ['enabled' => true, 'verify' => true]);

        expect(fn () => app(ServerRegistry::class)->resolve('behind-bastion'))
            ->toThrow(TunnelFailed::class, 'the MySQL TLS relay did not start: no PHP CLI binary was found')
            ->and($this->driver->tunnels[0]->closed)->toBeTrue();
    });
});

<?php

declare(strict_types=1);

use Simtabi\Laranail\DBConsole\Encryption\SessionTlsProbe;

it('cannot measure sqlite, which has no transport to encrypt', function (): void {
    config()->set('database.connections.probe_sqlite', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);

    expect(new SessionTlsProbe()->isEncrypted(app('db')->connection('probe_sqlite')))->toBeNull();
});

it('reports unknown, not plaintext, when the server cannot be asked', function (): void {
    // Nothing listens on port 1; the probe must swallow the connect failure.
    config()->set('database.connections.probe_dead', [
        'driver' => 'pgsql', 'host' => '127.0.0.1', 'port' => 1, 'database' => 'x', 'username' => 'x', 'password' => 'x',
    ]);

    expect(new SessionTlsProbe()->isEncrypted(app('db')->connection('probe_dead')))->toBeNull();
});

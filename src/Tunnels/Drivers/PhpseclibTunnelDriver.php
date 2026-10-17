<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DBConsole\Tunnels\Drivers;

use ReflectionClass;
use phpseclib4\Net\SSH2;
use Composer\Autoload\ClassLoader;
use Symfony\Component\Process\Process;
use Symfony\Component\Process\InputStream;
use Simtabi\Laranail\DBConsole\Tunnels\Endpoint;
use Simtabi\Laranail\DBConsole\Tunnels\TunnelSpec;
use Symfony\Component\Process\PhpExecutableFinder;
use Simtabi\Laranail\DBConsole\Tunnels\SshSettings;
use Simtabi\Laranail\DBConsole\Enums\TunnelDriverType;
use Simtabi\Laranail\DBConsole\Exceptions\TunnelFailed;
use Simtabi\Laranail\DBConsole\Tunnels\Contracts\Tunnel;
use Simtabi\Laranail\DBConsole\Tunnels\Contracts\TunnelDriver;

/**
 * Tunnels in pure PHP with phpseclib, for hosts with no ssh binary. The
 * forwarding runs in a child PHP process (ForwardPump), because the request
 * that uses the tunnel is blocked inside PDO and cannot move bytes itself.
 *
 * The child gets its settings, private key included, on stdin, verifies the
 * pinned host key before it authenticates, and exits when its stdin closes,
 * which happens when this process closes the tunnel or dies.
 */
final readonly class PhpseclibTunnelDriver implements TunnelDriver
{
    public static function script(): string
    {
        return dirname(__DIR__, 3) . '/resources/bin/phpseclib-tunnel.php';
    }

    public function type(): TunnelDriverType
    {
        return TunnelDriverType::Phpseclib;
    }

    public function unavailableReason(SshSettings $ssh): ?string
    {
        if (! class_exists(SSH2::class)) {
            return 'phpseclib is not installed: composer require phpseclib/phpseclib:^4.0.1';
        }

        return $this->php() === null ? 'no PHP CLI binary was found to run the tunnel process' : null;
    }

    public function open(TunnelSpec $spec): Tunnel
    {
        $reason = $this->unavailableReason($spec->ssh);
        if ($reason !== null) {
            throw TunnelFailed::forServer($spec->server, $reason);
        }

        $input = new InputStream;
        $process = new Process([(string) $this->php(), self::script(), $this->autoloader()]);
        $process->setInput($input);
        $process->setTimeout(null);
        $process->start();

        $input->write(json_encode($this->settings($spec), JSON_THROW_ON_ERROR) . "\n");

        $local = $this->awaitReady($process, $spec);
        if (! $local instanceof Endpoint) {
            $input->close();
            $process->stop(1);
            $spec->directory->remove();

            $stderr = trim((string) preg_replace('/\s+/', ' ', $process->getErrorOutput()));

            throw TunnelFailed::forServer(
                $spec->server,
                $stderr !== '' ? $stderr : "the phpseclib tunnel process exited with status {$process->getExitCode()}",
            );
        }

        return new PhpseclibTunnel($process, $input, $local, $spec->directory);
    }

    private function autoloader(): string
    {
        // vendor/composer/ClassLoader.php -> vendor/autoload.php
        return dirname((string) new ReflectionClass(ClassLoader::class)->getFileName(), 2) . '/autoload.php';
    }

    /**
     * @return array<string, mixed>
     */
    private function settings(TunnelSpec $spec): array
    {
        $remote = $spec->remote;

        return [
            'host'            => $spec->ssh->host,
            'port'            => $spec->ssh->port,
            'user'            => $spec->ssh->user,
            'host_key'        => "{$spec->ssh->hostKey->type} {$spec->ssh->hostKey->key}",
            'private_key'     => (string) file_get_contents($spec->privateKeyPath),
            'remote'          => $remote->isSocket() ? ['socket' => $remote->socket] : ['host' => $remote->host, 'port' => $remote->port],
            'listen'          => $spec->localSocket !== null ? ['socket' => $spec->localSocket] : [],
            'connect_timeout' => $spec->ssh->connectTimeout,
            'keepalive'       => $spec->ssh->keepAlive,
        ];
    }

    private function awaitReady(Process $process, TunnelSpec $spec): ?Endpoint
    {
        $deadline = microtime(true) + $spec->ssh->connectTimeout + 10;

        while (microtime(true) < $deadline) {
            $output = $process->getOutput();

            if (preg_match('/^READY tcp (\S+) (\d+)$/m', $output, $match) === 1) {
                return Endpoint::tcp($match[1], (int) $match[2]);
            }

            if (preg_match('/^READY unix (\S+)$/m', $output, $match) === 1) {
                return Endpoint::socket($match[1]);
            }

            if (! $process->isRunning()) {
                return null;
            }

            usleep(50_000);
        }

        return null;
    }

    private function php(): ?string
    {
        // Under php-fpm, PHP_BINARY is the fpm binary; the finder looks past it.
        $php = new PhpExecutableFinder()->find(false);

        return is_string($php) && $php !== '' ? $php : null;
    }
}

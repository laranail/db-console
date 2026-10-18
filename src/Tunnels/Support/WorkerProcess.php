<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DBConsole\Tunnels\Support;

use ReflectionClass;
use RuntimeException;
use Composer\Autoload\ClassLoader;
use Symfony\Component\Process\Process;
use Symfony\Component\Process\InputStream;
use Simtabi\Laranail\DBConsole\Tunnels\Endpoint;
use Symfony\Component\Process\PhpExecutableFinder;

/**
 * A PHP child process doing a tunnel's work beside the request: the phpseclib
 * pump, or the TLS relay. The request itself is blocked inside PDO, so it
 * cannot move bytes.
 *
 * The protocol is the same for every worker: settings arrive as one JSON line
 * on stdin, never on the command line where any local user could read them;
 * the worker answers `READY tcp <host> <port>` or `READY unix <path>` once it
 * listens; and it exits when its stdin closes, so it never outlives the
 * process that started it.
 */
final readonly class WorkerProcess
{
    private function __construct(
        public Process $process,
        public InputStream $input,
        public Endpoint $local,
    ) {}

    /**
     * Start the script and wait until it listens.
     *
     * @param array<string, mixed> $settings
     *
     * @throws RuntimeException naming why the worker did not come up
     */
    public static function start(string $script, array $settings, int $readyWithinSeconds, string $label): self
    {
        $php = self::php() ?? throw new RuntimeException('no PHP CLI binary was found to run the ' . $label . ' process');

        $input = new InputStream;
        $process = new Process([$php, $script, self::autoloader()]);
        $process->setInput($input);
        $process->setTimeout(null);
        $process->start();

        $input->write(json_encode($settings, JSON_THROW_ON_ERROR) . "\n");

        $local = self::awaitReady($process, $readyWithinSeconds);
        if (! $local instanceof Endpoint) {
            $input->close();
            $process->stop(1);

            $stderr = trim((string) preg_replace('/\s+/', ' ', $process->getErrorOutput()));

            throw new RuntimeException(
                $stderr !== '' ? $stderr : "the {$label} process exited with status {$process->getExitCode()}",
            );
        }

        return new self($process, $input, $local);
    }

    public static function php(): ?string
    {
        // Under php-fpm, PHP_BINARY is the fpm binary; the finder looks past it.
        $php = new PhpExecutableFinder()->find(false);

        return is_string($php) && $php !== '' ? $php : null;
    }

    public function isRunning(): bool
    {
        return $this->process->isRunning();
    }

    /**
     * Closing stdin is how a worker is told to stop; stop() is only for one
     * that does not.
     */
    public function stop(): void
    {
        $this->input->close();
        $this->process->stop(3);
    }

    private static function awaitReady(Process $process, int $seconds): ?Endpoint
    {
        $deadline = microtime(true) + $seconds;

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

    private static function autoloader(): string
    {
        // vendor/composer/ClassLoader.php -> vendor/autoload.php
        return dirname((string) new ReflectionClass(ClassLoader::class)->getFileName(), 2) . '/autoload.php';
    }
}

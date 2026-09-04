<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DBConsole\Logging;

use Throwable;
use Psr\Log\LoggerInterface;
use Simtabi\Laranail\DBConsole\Enums\Severity;
use Illuminate\Contracts\Config\Repository as Config;
use Simtabi\Laranail\DBConsole\Events\DBConsoleEvent;
use Simtabi\Laranail\DBConsole\Exceptions\DBConsoleException;

/**
 * Structured logging on a dedicated channel (default db-console), so
 * DBConsole activity is separable from app logs. Every entry carries the
 * action, server, actor, target, outcome, and — on failure — the
 * exception's sanitized context(). Secrets are impossible to log (value
 * objects redact themselves); the ContextScrubber is the belt-and-braces
 * second pass.
 */
final readonly class DBConsoleLogger
{
    public function __construct(
        private LoggerInterface $logger,
        private Config $config,
        private ContextScrubber $scrubber,
    ) {}

    public function event(DBConsoleEvent $event): void
    {
        $this->write($event->severity(), $event->operation()->value, [
            'server'  => $event->server,
            'target'  => $event->target(),
            'outcome' => $event->outcome()->value,
            ...$event->context,
        ]);
    }

    /**
     * @param array<string, mixed> $context
     */
    public function success(string $action, string $server, array $context = []): void
    {
        $this->write(Severity::Info, $action, ['server' => $server, ...$context]);
    }

    public function failure(string $action, string $server, DBConsoleException $exception): void
    {
        $severity = $exception->code()->value === 'rollback.failed'
            || $exception->code()->value === 'secret.unavailable'
            ? Severity::Critical
            : Severity::Error;

        $this->write($severity, $action, [
            'server'       => $server,
            'code'         => $exception->code()->value,
            'user_message' => $exception->userMessage(),
            ...$exception->context(),
        ]);
    }

    /**
     * @param array<string, mixed> $context
     */
    public function write(Severity $severity, string $action, array $context): void
    {
        $this->channel()->log(
            $severity->psrLevel(),
            "db-console: {$action}",
            $this->scrubber->scrub($context),
        );
    }

    private function channel(): LoggerInterface
    {
        $channel = $this->config->get('laranail.db-console.logging.channel', 'db-console');

        // Fall back to the default logger when the app has no such channel
        // configured, so logging never throws.
        if (! is_string($channel) || ! method_exists($this->logger, 'channel')) {
            return $this->logger;
        }

        try {
            /** @var LoggerInterface $resolved */
            $resolved = $this->logger->channel($channel);

            return $resolved;
        } catch (Throwable) {
            return $this->logger;
        }
    }
}

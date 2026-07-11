<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DBConsole\Listeners;

use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Http\Client\Factory as HttpFactory;
use Simtabi\Laranail\DBConsole\Events\Contracts\RecordsToAudit;
use Simtabi\Laranail\DBConsole\Logging\DBConsoleLogger;
use Throwable;

/**
 * The high-severity subset (drop failures, RollbackFailed, SuspiciousActivity,
 * a root-like admin) routed to a dedicated alert channel, separate from
 * routine notifications, so a loud event surfaces loudly (section 18). Fires
 * only when the event's severity alerts (warning/critical).
 */
final readonly class RaiseAlerts
{
    public function __construct(
        private Config $config,
        private DBConsoleLogger $log,
        private HttpFactory $http,
    ) {}

    public function handle(RecordsToAudit $event): void
    {
        if (! $event->severity()->alerts()) {
            return;
        }

        // Always record the alert on the log channel at its severity.
        $this->log->write($event->severity(), 'alert.' . $event->operation()->value, [
            'server' => $event->serverName(),
            'target' => $event->target(),
            'outcome' => $event->outcome()->value,
            'alert' => true,
        ]);

        $webhook = $this->config->get('laranail.db-console.alerts.webhook');
        if (is_string($webhook) && $webhook !== '') {
            $this->postToWebhook($webhook, $event);
        }
    }

    private function postToWebhook(string $webhook, RecordsToAudit $event): void
    {
        try {
            $this->http->timeout(5)->post($webhook, [
                'text' => sprintf(
                    'db-console alert [%s]: %s on %s (%s)',
                    $event->severity()->value,
                    $event->operation()->value,
                    $event->serverName(),
                    $event->target() ?? 'n/a',
                ),
            ]);
        } catch (Throwable $e) {
            // An alert-delivery failure must not cascade; record it and move on.
            // Redact the webhook URL before logging (failure-handling standard,
            // rule 15): an HTTP transport error routinely embeds the full URL,
            // whose path is a bearer token — the key-based ContextScrubber can't
            // reach into a free-text exception message, so strip the known secret
            // here and keep the exception class for diagnosis.
            $this->log->write($event->severity(), 'alert.delivery_failed', [
                'server' => $event->serverName(),
                'exception' => $e::class,
                'error' => str_replace($webhook, '[redacted-webhook]', $e->getMessage()),
            ]);
        }
    }
}

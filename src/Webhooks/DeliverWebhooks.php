<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DBConsole\Webhooks;

use ReflectionClass;
use Simtabi\Laranail\DBConsole\Enums\WebhookEvent;
use Simtabi\Laranail\DBConsole\Secrets\SecretVault;
use Illuminate\Contracts\Config\Repository as Config;
use Simtabi\Laranail\DBConsole\Models\WebhookSubscription;
use Simtabi\Laranail\DBConsole\Events\Contracts\RecordsToAudit;

/**
 * On every domain event, fan out to the webhook subscriptions that (a) are
 * active, (b) listen to this event type, and (c) cover this server (scope
 * filtering). For each match it builds the secret-free signed payload and
 * queues one DeliverWebhook job. The secret is read from the vault here and
 * used only to compute the HMAC — it never reaches the job or the payload.
 */
final readonly class DeliverWebhooks
{
    public function __construct(
        private Config $config,
        private SecretVault $vault,
    ) {}

    public function handle(RecordsToAudit $event): void
    {
        if (! (bool) $this->config->get('laranail.db-console.webhooks.enabled', false)) {
            return;
        }

        $webhookEvent = $this->webhookEventFor($event);
        if (! $webhookEvent instanceof WebhookEvent) {
            return;
        }

        $occurredAt = now()->toIso8601String();

        foreach ($this->matchingSubscriptions($webhookEvent->value, $event->serverName()) as $subscription) {
            $secret = $this->vault->reveal($subscription->secret_ref)->reveal();
            $payload = SignedPayload::build($event, $webhookEvent->value, $secret, $occurredAt);

            $pending = DeliverWebhook::dispatch((string) $subscription->id, $payload->event, $payload->body, $payload->signature);

            $queue = $this->queue();
            if ($queue !== null) {
                $pending->onQueue($queue);
            }
        }
    }

    /**
     * webhooks.queue: a queue name sends deliveries there; true (the default),
     * or anything that is not a name, leaves them on the default queue.
     */
    private function queue(): ?string
    {
        $queue = $this->config->get('laranail.db-console.webhooks.queue', true);

        return is_string($queue) && $queue !== '' ? $queue : null;
    }

    /**
     * @return iterable<WebhookSubscription>
     */
    private function matchingSubscriptions(string $event, string $server): iterable
    {
        return WebhookSubscription::query()
            ->where('active', true)
            ->get()
            ->filter(static fn (WebhookSubscription $s): bool => $s->listensTo($event) && $s->coversServer($server));
    }

    /**
     * Map a domain event to its webhook event by matching the event class
     * basename to a WebhookEvent case name (they are kept in lockstep). An
     * event with no webhook analogue is not delivered.
     */
    private function webhookEventFor(RecordsToAudit $event): ?WebhookEvent
    {
        $basename = new ReflectionClass($event)->getShortName();

        foreach (WebhookEvent::cases() as $case) {
            if ($case->name === $basename) {
                return $case;
            }
        }

        return null;
    }
}

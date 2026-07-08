<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DBConsole\Webhooks;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Queue\InteractsWithQueue;
use Simtabi\Laranail\DBConsole\Enums\Severity;
use Simtabi\Laranail\DBConsole\Events\SuspiciousActivity;
use Simtabi\Laranail\DBConsole\Logging\DBConsoleLogger;
use Simtabi\Laranail\DBConsole\Models\WebhookDelivery;
use Simtabi\Laranail\DBConsole\Models\WebhookSubscription;
use Throwable;

/**
 * Delivers one pre-signed, secret-free payload to one subscription, with
 * retry + backoff and auto-disable after repeated failure. Queued so a slow
 * or dead endpoint never blocks a database operation. The body + signature
 * are carried on the job (both plain strings, no secret) so a retry is
 * self-contained; the receiver verifies the HMAC signature.
 */
final class DeliverWebhook implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 1;   // retries are managed explicitly via backoff below

    public function __construct(
        public readonly string $subscriptionId,
        public readonly string $eventName,
        public readonly string $body,
        public readonly string $signature,
        public readonly int $attempt = 1,
    ) {}

    public function handle(HttpFactory $http, Config $config, DBConsoleLogger $log): void
    {
        $subscription = WebhookSubscription::query()->find($this->subscriptionId);
        if (! $subscription instanceof WebhookSubscription || ! $subscription->active) {
            return;
        }

        $timeout = (int) $config->get('laranail.db-console.webhooks.timeout', 5);
        $maxAttempts = (int) $config->get('laranail.db-console.webhooks.max_attempts', 5);
        $payloadHash = hash('sha256', $this->body);

        try {
            $response = $http->timeout($timeout)
                ->withHeaders([
                    'X-DBConsole-Event' => $this->eventName,
                    'X-DBConsole-Signature' => $this->signature,
                ])
                ->withBody($this->body, 'application/json')
                ->post($subscription->url);

            $this->recordDelivery($subscription, $payloadHash, $response->status(), $response->successful());

            if ($response->successful()) {
                $subscription->forceFill(['failure_count' => 0])->save();

                return;
            }

            $this->handleFailure($subscription, $maxAttempts, $log, "HTTP {$response->status()}");
        } catch (Throwable $e) {
            $this->recordDelivery($subscription, $payloadHash, null, false);
            $this->handleFailure($subscription, $maxAttempts, $log, $e->getMessage());
        }
    }

    private function handleFailure(WebhookSubscription $subscription, int $maxAttempts, DBConsoleLogger $log, string $reason): void
    {
        $subscription->forceFill(['failure_count' => $subscription->failure_count + 1])->save();

        if ($this->attempt >= $maxAttempts) {
            // Auto-disable after repeated failure, and alert.
            $subscription->forceFill(['active' => false])->save();
            $log->write(Severity::Warning, 'webhook.auto_disabled', [
                'subscription' => $subscription->id,
                'url' => $subscription->url,
                'reason' => $reason,
            ]);
            event(new SuspiciousActivity('global', "webhook {$subscription->id} auto-disabled after {$this->attempt} failures"));

            return;
        }

        // Exponential backoff: 2^attempt seconds. The retry carries the same
        // body + signature, so it is self-contained.
        self::dispatch($this->subscriptionId, $this->eventName, $this->body, $this->signature, $this->attempt + 1)
            ->delay(2 ** $this->attempt);
    }

    private function recordDelivery(WebhookSubscription $subscription, string $payloadHash, ?int $status, bool $success): void
    {
        WebhookDelivery::query()->create([
            'subscription_id' => $subscription->id,
            'event' => $this->eventName,
            'payload_hash' => $payloadHash,
            'response_status' => $status,
            'attempt' => $this->attempt,
            'delivered_at' => $success ? now() : null,
            'failed_at' => $success ? null : now(),
        ]);
    }
}

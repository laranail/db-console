<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DBConsole\Webhooks;

use Simtabi\Laranail\DBConsole\Enums\ConsolePermission;
use Simtabi\Laranail\DBConsole\Models\WebhookSubscription;
use Simtabi\Laranail\DBConsole\Secrets\Secret;
use Simtabi\Laranail\DBConsole\Secrets\SecretVault;
use Simtabi\Laranail\DBConsole\Services\Access\Authorizer;

/**
 * Manages webhook subscriptions. Subscribing mints a signing secret, stores
 * it via the SecretVault (never in the clear on the subscription row), and
 * returns it exactly once. Every operation goes through the same Gate as the
 * rest of DBConsole (webhook management is an admin capability).
 */
final readonly class WebhookManager
{
    public function __construct(
        private SecretVault $vault,
        private Authorizer $authorizer,
    ) {}

    /**
     * @param  list<string>  $events
     * @return array{0: WebhookSubscription, 1: string} the subscription and its one-time signing secret
     */
    public function subscribe(string $url, array $events, ?string $server = null): array
    {
        $this->authorizer->authorize(ConsolePermission::WebhookManage, 'global');

        $secret = Secret::generate(40);
        $ref = 'webhook:'.bin2hex(random_bytes(8));
        $this->vault->store($ref, $secret);

        $subscription = WebhookSubscription::query()->create([
            'url' => $url,
            'events' => $events,
            'secret_ref' => $ref,
            'active' => true,
            'server' => $server,
            'failure_count' => 0,
        ]);

        return [$subscription, $secret->reveal()];
    }

    public function unsubscribe(string $id): void
    {
        $this->authorizer->authorize(ConsolePermission::WebhookManage, 'global');

        $subscription = WebhookSubscription::query()->find($id);
        if ($subscription instanceof WebhookSubscription) {
            $this->vault->forget($subscription->secret_ref);
            $subscription->delete();
        }
    }
}

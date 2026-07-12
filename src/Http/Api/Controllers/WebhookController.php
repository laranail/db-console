<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DBConsole\Http\Api\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Simtabi\Laranail\DBConsole\Models\WebhookSubscription;
use Simtabi\Laranail\DBConsole\Validation\Requests\WebhookRequest;
use Simtabi\Laranail\DBConsole\Webhooks\WebhookManager;

/**
 * Webhook subscriptions API. Creating a subscription mints a signing secret,
 * returned ONCE (like a password); it is stored via the SecretVault by
 * reference, never in the clear.
 */
final class WebhookController
{
    public function index(): JsonResponse
    {
        $data = WebhookSubscription::query()->get()->map(static fn (WebhookSubscription $s): array => [
            'id' => $s->id,
            'url' => $s->url,
            'events' => $s->events,
            'active' => $s->active,
            'server' => $s->server,
            'failure_count' => $s->failure_count,
        ])->all();

        return new JsonResponse(['data' => $data]);
    }

    public function store(WebhookRequest $request, WebhookManager $webhooks): JsonResponse
    {
        /** @var list<string> $events */
        $events = (array) $request->validated('events');

        [$subscription, $secret] = $webhooks->subscribe(
            url: (string) $request->validated('url'),
            events: $events,
            server: $request->validated('server') !== null ? (string) $request->validated('server') : null,
        );

        return new JsonResponse([
            'id' => $subscription->id,
            'url' => $subscription->url,
            'events' => $subscription->events,
            'signing_secret' => $secret,   // shown once
        ], 201);
    }

    public function destroy(Request $request, string $id, WebhookManager $webhooks): JsonResponse
    {
        $webhooks->unsubscribe($id);

        return new JsonResponse(['message' => 'Webhook subscription removed.']);
    }
}

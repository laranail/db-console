<?php

declare(strict_types=1);

use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Simtabi\Laranail\DBConsole\Events\DatabaseCreated;
use Simtabi\Laranail\DBConsole\Events\DatabaseDropped;
use Simtabi\Laranail\DBConsole\Logging\DBConsoleLogger;
use Simtabi\Laranail\DBConsole\Models\WebhookSubscription;
use Simtabi\Laranail\DBConsole\Secrets\Secret;
use Simtabi\Laranail\DBConsole\Secrets\SecretVault;
use Simtabi\Laranail\DBConsole\Webhooks\DeliverWebhook;
use Simtabi\Laranail\DBConsole\Webhooks\DeliverWebhooks;
use Simtabi\Laranail\DBConsole\Webhooks\SignedPayload;

beforeEach(function (): void {
    $this->migrateCatalog();
    config()->set('laranail.db-console.webhooks.enabled', true);

    $this->secret = 'whsec_test_0123456789abcdef';
    app(SecretVault::class)->store('webhook:test', new Secret($this->secret));

    $this->sub = WebhookSubscription::query()->create([
        'url' => 'https://hooks.example.com/db',
        'events' => ['database.dropped'],
        'secret_ref' => 'webhook:test',
        'active' => true,
        'server' => null,
        'failure_count' => 0,
    ]);
});

it('queues one delivery per matching subscription on a subscribed event', function (): void {
    Queue::fake();

    app(DeliverWebhooks::class)->handle(new DatabaseDropped('prod-mysql', ['target' => 'shop_prod']));

    Queue::assertPushed(DeliverWebhook::class, 1);
});

it('does not deliver for an event the subscription does not listen to', function (): void {
    Queue::fake();

    app(DeliverWebhooks::class)->handle(new DatabaseCreated('prod-mysql', ['target' => 'shop_prod']));

    Queue::assertNothingPushed();
});

it('filters by server scope: a server-scoped subscription ignores other servers', function (): void {
    Queue::fake();
    $this->sub->forceFill(['server' => 'other-mysql'])->save();

    app(DeliverWebhooks::class)->handle(new DatabaseDropped('prod-mysql', ['target' => 'shop_prod']));

    Queue::assertNothingPushed();
});

it('signs the payload with HMAC and never includes a secret', function (): void {
    $payload = SignedPayload::build(
        new DatabaseDropped('prod-mysql', ['target' => 'shop_prod']),
        'database.dropped',
        $this->secret,
        '2026-07-08T00:00:00+00:00',
    );

    // Signature verifies against the shared secret.
    $expected = 'sha256=' . hash_hmac('sha256', $payload->body, $this->secret);
    expect($payload->signature)->toBe($expected)
        // The body carries the FACT, never the signing secret or any password.
        ->and($payload->body)->not->toContain($this->secret)
        ->and($payload->body)->toContain('database.dropped')
        ->and($payload->body)->toContain('shop_prod');
});

it('records a successful delivery and resets the failure count', function (): void {
    Http::fake(['*' => Http::response('ok', 200)]);

    new DeliverWebhook((string) $this->sub->id, 'database.dropped', '{"event":"database.dropped"}', 'sha256=x')
        ->handle(app(Factory::class), app('config'), app(DBConsoleLogger::class));

    expect($this->sub->fresh()->deliveries()->where('response_status', 200)->exists())->toBeTrue()
        ->and($this->sub->fresh()->failure_count)->toBe(0);
});

it('auto-disables the subscription after the max failed attempts and alerts', function (): void {
    Http::fake(['*' => Http::response('nope', 500)]);
    config()->set('laranail.db-console.webhooks.max_attempts', 3);

    // Deliver at the final attempt → auto-disable.
    new DeliverWebhook((string) $this->sub->id, 'database.dropped', '{"e":1}', 'sha256=x', attempt: 3)
        ->handle(app(Factory::class), app('config'), app(DBConsoleLogger::class));

    expect($this->sub->fresh()->active)->toBeFalse();
});

it('retries with backoff (re-dispatches the next attempt) before the cap', function (): void {
    Queue::fake();
    Http::fake(['*' => Http::response('nope', 503)]);
    config()->set('laranail.db-console.webhooks.max_attempts', 5);

    new DeliverWebhook((string) $this->sub->id, 'database.dropped', '{"e":1}', 'sha256=x', attempt: 1)
        ->handle(app(Factory::class), app('config'), app(DBConsoleLogger::class));

    // A follow-up attempt was queued (not auto-disabled yet).
    Queue::assertPushed(DeliverWebhook::class, fn (DeliverWebhook $job): bool => $job->attempt === 2);
    expect($this->sub->fresh()->active)->toBeTrue();
});

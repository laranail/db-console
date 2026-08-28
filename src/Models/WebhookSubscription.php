<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DBConsole\Models;

use Override;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A webhook subscriber: a target URL, the event types it listens to, a
 * signing secret (stored via the SecretVault by reference, never in the
 * clear here), an active flag, and an optional server scope. Payloads
 * delivered to it never contain a secret.
 *
 * @property string $url
 * @property list<string> $events
 * @property string $secret_ref
 * @property bool $active
 * @property ?string $server
 * @property int $failure_count
 * @property ?string $created_by
 */
final class WebhookSubscription extends CatalogModel
{
    protected string $baseTable = 'webhook_subscriptions';

    protected $guarded = [];

    /**
     * @return HasMany<WebhookDelivery, $this>
     */
    public function deliveries(): HasMany
    {
        return $this->hasMany(WebhookDelivery::class, 'subscription_id');
    }

    public function listensTo(string $event): bool
    {
        return in_array($event, $this->events, true);
    }

    public function coversServer(string $server): bool
    {
        return in_array($this->server, [null, '', $server], true);
    }

    /**
     * @return array<string, string>
     */
    #[Override]
    protected function casts(): array
    {
        return [
            'events'        => 'array',
            'active'        => 'boolean',
            'failure_count' => 'integer',
        ];
    }
}

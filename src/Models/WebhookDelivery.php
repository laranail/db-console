<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DBConsole\Models;

use Override;
use Illuminate\Support\Carbon;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One delivery attempt: the subscription, the event, a payload hash (not the
 * payload — which is transient and secret-free anyway), the response status,
 * the attempt number, and when it delivered or failed. Drives retry/backoff
 * and auto-disable.
 *
 * @property string $subscription_id
 * @property string $event
 * @property string $payload_hash
 * @property ?int $response_status
 * @property int $attempt
 * @property ?Carbon $delivered_at
 * @property ?Carbon $failed_at
 */
final class WebhookDelivery extends CatalogModel
{
    protected string $baseTable = 'webhook_deliveries';

    protected $guarded = [];

    /**
     * @return BelongsTo<WebhookSubscription, $this>
     */
    public function subscription(): BelongsTo
    {
        return $this->belongsTo(WebhookSubscription::class, 'subscription_id');
    }

    /**
     * @return array<string, string>
     */
    #[Override]
    protected function casts(): array
    {
        return [
            'response_status' => 'integer',
            'attempt'         => 'integer',
            'delivered_at'    => 'datetime',
            'failed_at'       => 'datetime',
        ];
    }
}

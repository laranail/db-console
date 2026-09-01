<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DBConsole\Webhooks;

use Simtabi\Laranail\DBConsole\Events\Contracts\RecordsToAudit;

/**
 * Builds the secret-free JSON payload for a webhook delivery and its HMAC
 * signature. The payload records only the FACT of what happened — event,
 * server, target, actor, outcome, time — never a password or connection
 * secret. The signature lets the receiver verify authenticity.
 */
final readonly class SignedPayload
{
    public function __construct(
        public string $event,
        public string $body,
        public string $signature,
    ) {}

    public static function build(RecordsToAudit $domainEvent, string $eventName, string $secret, string $occurredAt): self
    {
        $body = (string) json_encode([
            'event' => $eventName,
            'server' => $domainEvent->serverName(),
            'target' => $domainEvent->target(),
            'outcome' => $domainEvent->outcome()->value,
            'occurred_at' => $occurredAt,
        ], JSON_UNESCAPED_SLASHES);

        $algo = (string) config('laranail.db-console.webhooks.sign_with', 'sha256');

        return new self(
            event: $eventName,
            body: $body,
            signature: $algo.'='.hash_hmac($algo, $body, $secret),
        );
    }

    public function hash(): string
    {
        return hash('sha256', $this->body);
    }
}

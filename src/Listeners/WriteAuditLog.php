<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DBConsole\Listeners;

use Illuminate\Contracts\Auth\Guard;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Database\DatabaseManager;
use Simtabi\Laranail\DBConsole\Audit\AuditChain;
use Simtabi\Laranail\DBConsole\Events\Contracts\RecordsToAudit;
use Simtabi\Laranail\DBConsole\Models\AuditLog;

/**
 * Appends one hash-chained row to the audit trail for every domain event.
 * Never stores secrets or raw errors — only the sanitized message from the
 * event's context. The chain append runs in a transaction so the
 * previous-hash linkage is consistent under concurrent writes.
 */
final readonly class WriteAuditLog
{
    public function __construct(
        private AuditChain $chain,
        private Guard $auth,
        private Config $config,
        private DatabaseManager $db,
    ) {}

    public function handle(RecordsToAudit $event): void
    {
        if (! (bool) $this->config->get('laranail.db-console.audit.enabled', true)) {
            return;
        }

        $connection = (string) $this->config->get('laranail.db-console.catalog.connection', 'db_console_catalog');

        $this->db->connection($connection)->transaction(function () use ($event): void {
            $previous = AuditLog::query()->orderBy('created_at')->orderByDesc('id')->value('hash');

            $engine = $event->auditContext()['engine'] ?? null;

            $row = new AuditLog([
                'action' => $event->operation(),
                'target' => $event->target(),
                'server' => $event->serverName(),
                'engine' => is_string($engine) ? $engine : null,
                'outcome' => $event->outcome(),
                'sanitized_message' => $this->sanitizedMessage($event),
                'actor_type' => $this->actorType(),
                'actor_id' => $this->actorId(),
                'ip' => $this->ip(),
            ]);
            $row->created_at = now();

            if ((bool) $this->config->get('laranail.db-console.audit.hash_chain', true)) {
                $row->previous_hash = is_string($previous) ? $previous : null;
                $row->hash = $this->chain->hash(
                    is_string($previous) ? $previous : null,
                    $this->chain->contentOf($row),
                );
            }

            $row->save();
        });
    }

    private function sanitizedMessage(RecordsToAudit $event): ?string
    {
        $context = $event->auditContext();
        $message = $context['user_message'] ?? $context['reason'] ?? null;

        return is_string($message) ? $message : null;
    }

    private function actorType(): ?string
    {
        $user = $this->auth->user();

        return $user === null ? null : $user::class;
    }

    private function actorId(): ?string
    {
        $id = $this->auth->id();

        return $id === null ? null : (string) $id;
    }

    private function ip(): ?string
    {
        $request = request();

        return $request->getClientIp();
    }
}

<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DBConsole\Models;

use Override;
use Illuminate\Support\Carbon;
use Illuminate\Database\Eloquent\Model;
use Simtabi\Laranail\Enumerator\Casts\AsEnum;
use Simtabi\Laranail\DBConsole\Enums\EngineType;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Simtabi\Laranail\DBConsole\Enums\OperationType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Simtabi\Laranail\DBConsole\Enums\OperationOutcome;
use Simtabi\Laranail\DBConsole\Database\Factories\AuditLogFactory;

/**
 * The append-only audit trail: who did what, to what, on which server, with
 * what outcome. Never contains secrets or raw driver errors (only the
 * sanitized message). A6 makes it tamper-evident (an observer blocks
 * updates/deletes, plus optional hash-chaining); this model defines the
 * shape and the enum casts.
 *
 * @property ?string $actor_type
 * @property ?string $actor_id
 * @property OperationType $action
 * @property ?string $target
 * @property string $server
 * @property ?EngineType $engine
 * @property OperationOutcome $outcome
 * @property ?string $sanitized_message
 * @property ?string $ip
 * @property ?string $previous_hash
 * @property ?string $hash
 * @property ?Carbon $created_at
 */
final class AuditLog extends CatalogModel
{
    /** @use HasFactory<AuditLogFactory> */
    use HasFactory;

    public const ?string UPDATED_AT = null;

    protected string $baseTable = 'audit_log';

    protected $guarded = [];

    /**
     * The audit log is the audit SINK, not an audited entity: it records its
     * own actor explicitly (actor_type/actor_id) and is append-only. So it
     * does NOT attach the db-tools blameable observer (which would stamp
     * created_by/updated_by columns this table deliberately does not have).
     * The append-only AuditLogObserver is attached separately by the provider.
     */
    #[Override]
    public static function booted(): void
    {
        // Intentionally empty — see the note above.
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function actor(): MorphTo
    {
        return $this->morphTo('actor');
    }

    protected static function newFactory(): AuditLogFactory
    {
        return AuditLogFactory::new();
    }

    /**
     * @return array<string, string>
     */
    #[Override]
    protected function casts(): array
    {
        return [
            'action'  => AsEnum::of(OperationType::class),
            'engine'  => AsEnum::of(EngineType::class),
            'outcome' => AsEnum::of(OperationOutcome::class),
        ];
    }
}

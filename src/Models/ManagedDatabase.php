<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DBConsole\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Override;
use Simtabi\Laranail\DBConsole\Database\Factories\ManagedDatabaseFactory;
use Simtabi\Laranail\DBConsole\Models\Concerns\OptimisticLocking;

/**
 * A database DBConsole created or adopted. is_managed distinguishes objects
 * created via DBConsole from ones seen/adopted; version guards concurrent
 * edits. Reads are always live — this row is history/ownership metadata, not
 * the source of truth about what exists on the server.
 *
 * @property string $server_name
 * @property string $name
 * @property ?string $charset
 * @property ?string $collation
 * @property bool $is_managed
 * @property int $version
 * @property ?string $created_by
 */
final class ManagedDatabase extends CatalogModel
{
    /** @use HasFactory<ManagedDatabaseFactory> */
    use HasFactory;

    use OptimisticLocking;

    protected string $baseTable = 'databases';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    #[Override]
    protected function casts(): array
    {
        return [
            'is_managed' => 'boolean',
            'version' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<DbServer, $this>
     */
    public function server(): BelongsTo
    {
        return $this->belongsTo(DbServer::class, 'server_name', 'name');
    }

    /**
     * @return HasMany<Grant, $this>
     */
    public function grants(): HasMany
    {
        return $this->hasMany(Grant::class, 'database_id');
    }

    protected static function newFactory(): ManagedDatabaseFactory
    {
        return ManagedDatabaseFactory::new();
    }
}

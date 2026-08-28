<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DBConsole\Models;

use Override;
use Simtabi\Laranail\Enumerator\Casts\AsEnum;
use Simtabi\Laranail\DBConsole\Enums\EngineType;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Simtabi\Laranail\DBConsole\Models\Concerns\OptimisticLocking;
use Simtabi\Laranail\DBConsole\Database\Factories\DbServerFactory;

/**
 * A registered server: name, engine, host/port, label, and a reference to
 * its admin connection config key — NEVER the secret (that lives behind the
 * SecretVault). Config-backed servers are read-only; catalog-backed rows are
 * editable and use optimistic locking.
 *
 * @property string $name
 * @property EngineType $engine
 * @property ?string $host
 * @property ?int $port
 * @property ?string $label
 * @property string $connection_ref
 * @property bool $is_managed
 * @property int $version
 */
final class DbServer extends CatalogModel
{
    /** @use HasFactory<DbServerFactory> */
    use HasFactory;

    use OptimisticLocking;

    protected string $baseTable = 'servers';

    protected $guarded = [];

    /**
     * @return HasMany<ManagedDatabase, $this>
     */
    public function databases(): HasMany
    {
        return $this->hasMany(ManagedDatabase::class, 'server_name', 'name');
    }

    /**
     * @return HasMany<DbAccount, $this>
     */
    public function accounts(): HasMany
    {
        return $this->hasMany(DbAccount::class, 'server_name', 'name');
    }

    protected static function newFactory(): DbServerFactory
    {
        return DbServerFactory::new();
    }

    /**
     * @return array<string, string>
     */
    #[Override]
    protected function casts(): array
    {
        return [
            'engine' => AsEnum::of(EngineType::class),
            // Topology columns are encrypted at rest (defense in depth); the
            // toggle is honored via the encrypted casts below.
            'host'       => 'encrypted',
            'is_managed' => 'boolean',
            'version'    => 'integer',
        ];
    }
}

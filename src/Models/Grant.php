<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DBConsole\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Override;
use Simtabi\Laranail\DBConsole\Database\Factories\GrantFactory;
use Simtabi\Laranail\DBConsole\Enums\GrantScope;
use Simtabi\Laranail\DBConsole\Enums\PrivilegePreset;
use Simtabi\Laranail\Enumerator\Casts\AsEnum;

/**
 * A privilege assignment: which account holds which preset/privileges on
 * which database. One row per (account, database) pair, so the dashboard can
 * show both "user has X on these databases" and "database is used by these
 * accounts" from the same data.
 *
 * @property string $account_id
 * @property string $database_id
 * @property PrivilegePreset $preset
 * @property list<string> $privileges
 * @property GrantScope $scope
 * @property ?string $granted_by
 */
final class Grant extends CatalogModel
{
    /** @use HasFactory<GrantFactory> */
    use HasFactory;

    protected string $baseTable = 'grants';

    protected $guarded = [];

    /**
     * @return BelongsTo<DbAccount, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(DbAccount::class, 'account_id');
    }

    /**
     * @return BelongsTo<ManagedDatabase, $this>
     */
    public function database(): BelongsTo
    {
        return $this->belongsTo(ManagedDatabase::class, 'database_id');
    }

    protected static function newFactory(): GrantFactory
    {
        return GrantFactory::new();
    }

    /**
     * @return array<string, string>
     */
    #[Override]
    protected function casts(): array
    {
        return [
            'preset' => AsEnum::of(PrivilegePreset::class),
            'scope' => AsEnum::of(GrantScope::class),
            'privileges' => 'array',
        ];
    }
}

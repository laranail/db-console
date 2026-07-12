<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DBConsole\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Override;
use Simtabi\Laranail\DBConsole\Database\Factories\DbAccountFactory;
use Simtabi\Laranail\DBConsole\Models\Concerns\OptimisticLocking;

/**
 * A database user/role. Records username, host, and last_password_rotated_at
 * — NEVER a password (credentials live behind the SecretVault, and account
 * passwords on the managed server are never stored here at all). is_managed
 * and version as on ManagedDatabase.
 *
 * @property string $server_name
 * @property string $username
 * @property string $host
 * @property ?Carbon $last_password_rotated_at
 * @property bool $is_managed
 * @property int $version
 */
final class DbAccount extends CatalogModel
{
    /** @use HasFactory<DbAccountFactory> */
    use HasFactory;

    use OptimisticLocking;

    protected string $baseTable = 'accounts';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    #[Override]
    protected function casts(): array
    {
        return [
            // Username/host are topology; encrypted at rest (defense in depth).
            'username' => 'encrypted',
            'host' => 'encrypted',
            'last_password_rotated_at' => 'datetime',
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
        return $this->hasMany(Grant::class, 'account_id');
    }

    protected static function newFactory(): DbAccountFactory
    {
        return DbAccountFactory::new();
    }
}

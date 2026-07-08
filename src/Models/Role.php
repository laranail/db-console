<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DBConsole\Models;

use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Override;

/**
 * A named console role (builtin driver only). Groups permissions; editable.
 * Not the same as a database privilege preset — this is who may drive the
 * tool, not what a database user can do.
 *
 * @property string $name
 * @property ?string $label
 * @property bool $is_shipped
 */
final class Role extends CatalogModel
{
    protected string $baseTable = 'roles';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    #[Override]
    protected function casts(): array
    {
        return ['is_shipped' => 'boolean'];
    }

    /**
     * @return BelongsToMany<Permission, $this>
     */
    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(
            Permission::class,
            $this->prefix() . 'role_permission',
            'role_id',
            'permission_id',
        );
    }
}

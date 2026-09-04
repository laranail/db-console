<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DBConsole\Models;

use Override;
use Illuminate\Database\Eloquent\Model;
use Simtabi\Laranail\Enumerator\Casts\AsEnum;
use Simtabi\Laranail\DBConsole\Enums\ScopeType;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * The (assignee, role, scope) triple that grants console access — the row the
 * gate resolves against (section 17). Used in BOTH RBAC modes, because scope
 * is DBConsole's own concept (Spatie has no scope model); only the
 * role→permission composition differs between drivers.
 *
 * The assignee is polymorphic: an app user now, a Team later, with no
 * enforcement change (scope is a first-class column from day one).
 *
 * @property string $assignee_type
 * @property string $assignee_id
 * @property string $role
 * @property ScopeType $scope_type
 * @property ?string $scope_ref
 */
final class RoleAssignment extends CatalogModel
{
    protected string $baseTable = 'role_assignments';

    protected $guarded = [];

    /**
     * @return MorphTo<Model, $this>
     */
    public function assignee(): MorphTo
    {
        return $this->morphTo('assignee');
    }

    /**
     * The wire scope string this assignment applies at.
     */
    public function scopeString(): string
    {
        return match ($this->scope_type) {
            ScopeType::Global   => 'global',
            ScopeType::Server   => 'server:' . $this->scope_ref,
            ScopeType::Database => 'database:' . $this->scope_ref,
        };
    }

    /**
     * @return array<string, string>
     */
    #[Override]
    protected function casts(): array
    {
        return ['scope_type' => AsEnum::of(ScopeType::class)];
    }
}

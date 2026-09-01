<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DBConsole\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * A named group of operators (designed-for, not built in v1). Reserved so the
 * schema exists and adopting Teams later is a config flip plus a migrate, not
 * a redesign: a Team can be the assignee of a RoleAssignment, so members
 * inherit its (role, scope). The gate resolver already carries scope on every
 * check, so no enforcement code changes when Teams lands.
 *
 * @property string $name
 */
final class Team extends CatalogModel
{
    protected string $baseTable = 'teams';

    protected $guarded = [];

    /**
     * @return BelongsToMany<Model, $this>
     */
    public function members(): BelongsToMany
    {
        /** @var class-string<Model> $userModel */
        $userModel = (string) config('laranail.db-console.rbac.user_model', '\App\Models\User');

        return $this->belongsToMany($userModel, $this->prefix().'team_user', 'team_id', 'user_id');
    }
}

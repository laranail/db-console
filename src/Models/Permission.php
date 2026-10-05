<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DBConsole\Models;

/**
 * A console ability string (laranail-db-console.database.create, ...), seeded from the
 * fixed ConsolePermission set (builtin driver only). Rows seeded before 0.1 carry the
 * bare db-console.* form and still resolve through ConsolePermission::fromAbility().
 *
 * @property string $name
 */
final class Permission extends CatalogModel
{
    protected string $baseTable = 'permissions';

    protected $guarded = [];
}

<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DBConsole\Models;

/**
 * A console ability string (db-console.database.create, ...), seeded from the
 * fixed ConsolePermission set (builtin driver only).
 *
 * @property string $name
 */
final class Permission extends CatalogModel
{
    protected string $baseTable = 'permissions';

    protected $guarded = [];
}

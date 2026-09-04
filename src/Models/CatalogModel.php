<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DBConsole\Models;

use Override;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Simtabi\Laranail\DbTools\Observers\AuditObserver;

/**
 * Base for every catalog model: ULID primary keys, the dedicated catalog
 * connection (never the app default), and the configurable db_console_
 * table prefix applied in one place. When laranail/db-tools is
 * installed its AuditObserver is attached (created_by/updated_by stamping);
 * without it the models still work, just without actor columns populated.
 */
abstract class CatalogModel extends Model
{
    use HasUlids;

    /** The unprefixed table name; the prefix is applied in getTable(). */
    protected string $baseTable = '';

    #[Override]
    public static function booted(): void
    {
        $observer = AuditObserver::class;
        if (class_exists($observer)) {
            /** @var class-string $observer */
            static::observe($observer);
        }
    }

    #[Override]
    public function getConnectionName(): ?string
    {
        return (string) config('laranail.db-console.catalog.connection', 'db_console_catalog');
    }

    #[Override]
    public function getTable(): string
    {
        return $this->prefix() . $this->baseTable;
    }

    protected function prefix(): string
    {
        return (string) config('laranail.db-console.catalog.prefix', 'db_console_');
    }
}

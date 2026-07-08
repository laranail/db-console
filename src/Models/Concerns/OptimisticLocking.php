<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DBConsole\Models\Concerns;

use Simtabi\Laranail\DBConsole\Exceptions\StaleModel;

/**
 * Optimistic locking on a `version` column (section 5, concurrency). New
 * rows start at version 1; a guarded update via saveOrConflict() bumps the
 * version only if the row still holds the version this instance loaded, so a
 * second operator editing the same DbServer/DbAccount gets a clear conflict
 * rather than silently overwriting the first.
 *
 * Plain save() is unguarded (used for internal, uncontended writes); the
 * edit flows that two operators might race use saveOrConflict().
 */
trait OptimisticLocking
{
    public static function bootOptimisticLocking(): void
    {
        static::creating(function ($model): void {
            if ($model->version === null) {
                $model->version = 1;
            }
        });
    }

    /**
     * Persist with the loaded version guarding the UPDATE. A zero-row update
     * means someone changed the row first → StaleModel.
     */
    public function saveOrConflict(): bool
    {
        if (! $this->exists) {
            return $this->save();
        }

        $expected = (int) $this->getOriginal('version');
        $changes = $this->getDirty();

        if ($changes === []) {
            return true;
        }

        $affected = static::query()
            ->where($this->getKeyName(), $this->getKey())
            ->where('version', $expected)
            ->update([...$changes, 'version' => $expected + 1]);

        if ($affected === 0) {
            throw StaleModel::forRecord($this->getTable(), (string) $this->getKey());
        }

        $this->version = $expected + 1;
        $this->syncChanges();
        $this->syncOriginal();

        return true;
    }
}

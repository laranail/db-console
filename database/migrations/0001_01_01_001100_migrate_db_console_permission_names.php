<?php

declare(strict_types=1);

use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\PermissionRegistrar;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Role as SpatieRole;
use Simtabi\Laranail\DBConsole\Enums\ConsolePermission;
use Spatie\Permission\Models\Permission as SpatiePermission;

/**
 * Bring stored console permissions up to date, so nothing has to read the
 * old names at runtime:
 *
 *  1. Rows saved before 0.1 under the bare `db-console.<permission>` name
 *     become `laranail-db-console.<permission>`. Where install already seeded
 *     the scoped row beside the old one, the old row's roles move to it and
 *     the old row goes, so no role loses a permission it held.
 *  2. The shipped Owner role gains `resource.adopt` (added in 0.1.1), which
 *     an install seeded before then never gave it. Nothing else about any
 *     role changes, unlike re-running install, which re-syncs the shipped
 *     roles in full.
 *
 * Both RBAC drivers: the builtin tables in the catalog, and Spatie's tables
 * when rbac.driver is spatie. Idempotent; down() is a no-op, because the old
 * names are no longer read by anything.
 */
return new class extends Migration
{
    private const string OLD_PREFIX = 'db-console.';

    public function up(): void
    {
        if ($this->driver() === 'spatie') {
            $this->migrateSpatie();

            return;
        }

        $this->migrateBuiltin();
    }

    public function down(): void {}

    private function migrateBuiltin(): void
    {
        $db = $this->catalog();
        $permissions = $this->table('permissions');
        $pivot = $this->table('role_permission');
        $roles = $this->table('roles');

        if (! $db->getSchemaBuilder()->hasTable($permissions)) {
            return;
        }

        $db->transaction(function () use ($db, $permissions, $pivot, $roles): void {
            foreach ($db->table($permissions)->where('name', 'like', self::OLD_PREFIX . '%')->get(['id', 'name']) as $old) {
                $scoped = ConsolePermission::ABILITY_PREFIX . substr((string) $old->name, strlen(self::OLD_PREFIX));
                $target = $db->table($permissions)->where('name', $scoped)->value('id');

                if ($target === null) {
                    $db->table($permissions)->where('id', $old->id)->update(['name' => $scoped, 'updated_at' => now()]);

                    continue;
                }

                foreach ($db->table($pivot)->where('permission_id', $old->id)->pluck('role_id') as $roleId) {
                    $db->table($pivot)->insertOrIgnore(['role_id' => $roleId, 'permission_id' => $target]);
                }

                $db->table($pivot)->where('permission_id', $old->id)->delete();
                $db->table($permissions)->where('id', $old->id)->delete();
            }

            $owner = $db->table($roles)->where('name', 'owner')->where('is_shipped', true)->value('id');
            if ($owner === null) {
                return;   // roles never seeded; install will seed them whole
            }

            $ability = ConsolePermission::ResourceAdopt->ability();
            $adopt = $db->table($permissions)->where('name', $ability)->value('id');
            if ($adopt === null) {
                $adopt = (string) Str::ulid();
                $db->table($permissions)->insert(['id' => $adopt, 'name' => $ability, 'created_at' => now(), 'updated_at' => now()]);
            }

            $db->table($pivot)->insertOrIgnore(['role_id' => $owner, 'permission_id' => $adopt]);
        });
    }

    private function migrateSpatie(): void
    {
        if (! class_exists(SpatiePermission::class)) {
            return;
        }

        $table = (string) config('permission.table_names.permissions', 'permissions');
        if (! Schema::connection((new SpatiePermission)->getConnectionName())->hasTable($table)) {
            return;
        }

        foreach (SpatiePermission::query()->where('name', 'like', self::OLD_PREFIX . '%')->get() as $old) {
            $scoped = ConsolePermission::ABILITY_PREFIX . substr((string) $old->name, strlen(self::OLD_PREFIX));
            $target = SpatiePermission::query()->where('name', $scoped)->where('guard_name', $old->guard_name)->first();

            if ($target === null) {
                $old->forceFill(['name' => $scoped])->save();

                continue;
            }

            foreach ($old->roles as $role) {
                $role->givePermissionTo($target);
            }

            $old->delete();
        }

        $owner = SpatieRole::query()->where('name', 'owner')->first();
        if ($owner !== null) {
            $owner->givePermissionTo(SpatiePermission::findOrCreate(ConsolePermission::ResourceAdopt->ability(), $owner->guard_name));
        }

        if (class_exists(PermissionRegistrar::class)) {
            app(PermissionRegistrar::class)->forgetCachedPermissions();
        }
    }

    private function driver(): string
    {
        return (string) config('laranail.db-console.rbac.driver', 'builtin');
    }

    private function catalog(): ConnectionInterface
    {
        return DB::connection((string) config('laranail.db-console.catalog.connection', 'db_console_catalog'));
    }

    private function table(string $name): string
    {
        return ((string) config('laranail.db-console.catalog.prefix', 'db_console_')) . $name;
    }
};

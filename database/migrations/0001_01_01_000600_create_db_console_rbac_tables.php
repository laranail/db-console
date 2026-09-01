<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;
use Illuminate\Support\Facades\Schema;

/**
 * Built-in RBAC tables (roles, permissions, pivot). Created only when
 * rbac.driver = builtin; in Spatie mode the role/permission storage is
 * Spatie's, so these are skipped. The role_assignments table (scope) is a
 * separate migration used in BOTH modes.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! $this->builtin()) {
            return;
        }

        $this->schema()->create($this->table('roles'), function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('name')->unique();
            $table->string('label')->nullable();
            $table->boolean('is_shipped')->default(false);
            $table->timestamps();
        });

        $this->schema()->create($this->table('permissions'), function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('name')->unique();
            $table->timestamps();
        });

        $this->schema()->create($this->table('role_permission'), function (Blueprint $table): void {
            $table->ulid('role_id');
            $table->ulid('permission_id');
            $table->primary(['role_id', 'permission_id']);
            $table->index('permission_id');
        });
    }

    public function down(): void
    {
        $this->schema()->dropIfExists($this->table('role_permission'));
        $this->schema()->dropIfExists($this->table('permissions'));
        $this->schema()->dropIfExists($this->table('roles'));
    }

    private function builtin(): bool
    {
        return (string) config('laranail.db-console.rbac.driver', 'builtin') === 'builtin';
    }

    private function schema(): Builder
    {
        return Schema::connection((string) config('laranail.db-console.catalog.connection', 'db_console_catalog'));
    }

    private function table(string $name): string
    {
        return ((string) config('laranail.db-console.catalog.prefix', 'db_console_')).$name;
    }
};

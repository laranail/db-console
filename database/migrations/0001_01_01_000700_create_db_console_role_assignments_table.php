<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;
use Illuminate\Support\Facades\Schema;

/**
 * The (assignee, role, scope) assignments — the rows the gate resolves
 * against. Used in BOTH RBAC modes because scope is DBConsole's own concept.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->schema()->create($this->table('role_assignments'), function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulidMorphs('assignee');            // user now, team later (indexes assignee_type+id)
            $table->string('role');
            $table->string('scope_type');              // global|server|database
            $table->string('scope_ref')->nullable();   // null|server|server/pattern
            $table->timestamps();

            $table->unique(['assignee_type', 'assignee_id', 'role', 'scope_type', 'scope_ref'], 'db_console_assignment_unique');
        });
    }

    public function down(): void
    {
        $this->schema()->dropIfExists($this->table('role_assignments'));
    }

    private function schema(): Builder
    {
        return Schema::connection((string) config('laranail.db-console.catalog.connection', 'db_console_catalog'));
    }

    private function table(string $name): string
    {
        return ((string) config('laranail.db-console.catalog.prefix', 'db_console_')) . $name;
    }
};

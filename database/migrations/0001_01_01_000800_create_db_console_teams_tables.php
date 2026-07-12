<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;
use Illuminate\Support\Facades\Schema;

/**
 * Teams stub tables. Ship DISABLED (rbac.teams.enabled=false) so the schema
 * is reserved and adopting Teams later is a config flip plus a migrate, not a
 * redesign. Created only when teams are enabled.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! $this->teamsEnabled()) {
            return;
        }

        $this->schema()->create($this->table('teams'), function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('name')->unique();
            $table->timestamps();
        });

        $this->schema()->create($this->table('team_user'), function (Blueprint $table): void {
            $table->ulid('team_id');
            $table->string('user_id');
            $table->primary(['team_id', 'user_id']);
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        $this->schema()->dropIfExists($this->table('team_user'));
        $this->schema()->dropIfExists($this->table('teams'));
    }

    private function teamsEnabled(): bool
    {
        return (bool) config('laranail.db-console.rbac.teams.enabled', false);
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

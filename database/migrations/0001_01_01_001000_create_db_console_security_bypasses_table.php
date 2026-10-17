<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Builder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

/**
 * The break-glass bypasses issued from the host's shell by
 * laranail::db-console.security.recover.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->schema()->create($this->table('security_bypasses'), function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('assignee_type');
            $table->string('assignee_id');
            $table->string('issued_by')->nullable();
            $table->timestamp('expires_at');
            $table->timestamps();

            $table->index(['assignee_type', 'assignee_id'], 'db_console_security_bypasses_assignee');
        });
    }

    public function down(): void
    {
        $this->schema()->dropIfExists($this->table('security_bypasses'));
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

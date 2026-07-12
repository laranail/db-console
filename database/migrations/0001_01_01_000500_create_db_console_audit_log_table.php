<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;
use Illuminate\Support\Facades\Schema;

/**
 * Append-only audit trail. created_at only (no updated_at); A6 attaches an
 * observer that blocks updates/deletes and hash-chains rows for
 * tamper-evidence. Never stores secrets or raw driver errors.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->schema()->create($this->table(), function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->nullableUlidMorphs('actor');
            $table->string('action');
            $table->string('target')->nullable();
            $table->string('server');
            $table->string('engine')->nullable();
            $table->string('outcome');
            $table->text('sanitized_message')->nullable();
            $table->string('ip', 45)->nullable();
            $table->string('previous_hash', 64)->nullable();
            $table->string('hash', 64)->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index('server');
            $table->index('action');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        $this->schema()->dropIfExists($this->table());
    }

    private function schema(): Builder
    {
        return Schema::connection((string) config('laranail.db-console.catalog.connection', 'db_console_catalog'));
    }

    private function table(): string
    {
        return ((string) config('laranail.db-console.catalog.prefix', 'db_console_')) . 'audit_log';
    }
};

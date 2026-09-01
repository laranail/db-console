<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;
use Illuminate\Support\Facades\Schema;

/**
 * Registered servers. Columns are declared explicitly (no dependency on the
 * db-tools blueprint macros), on the dedicated catalog connection.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->schema()->create($this->table(), function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('name')->unique();
            $table->string('engine');
            $table->text('host')->nullable();          // encrypted at rest
            $table->unsignedInteger('port')->nullable();
            $table->string('label')->nullable();
            $table->string('connection_ref');          // config key, never the secret
            $table->boolean('is_managed')->default(true);
            $table->unsignedInteger('version')->default(1);
            $table->ulid('created_by')->nullable();
            $table->ulid('updated_by')->nullable();
            $table->timestamps();
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
        return ((string) config('laranail.db-console.catalog.prefix', 'db_console_')).'servers';
    }
};

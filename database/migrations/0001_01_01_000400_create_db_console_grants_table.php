<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->schema()->create($this->table(), function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulid('account_id');
            $table->ulid('database_id');
            $table->string('preset');
            $table->json('privileges');
            $table->string('scope')->default('database');
            $table->ulid('granted_by')->nullable();
            $table->timestamps();

            $table->unique(['account_id', 'database_id']);
            $table->index('database_id');
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
        return ((string) config('laranail.db-console.catalog.prefix', 'db_console_')).'grants';
    }
};

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
            $table->string('server_name');
            $table->text('username');   // encrypted at rest
            $table->text('host');       // encrypted at rest
            $table->string('username_hash')->nullable();  // lookup helper (non-reversible)
            $table->timestamp('last_password_rotated_at')->nullable();
            $table->boolean('is_managed')->default(true);
            $table->unsignedInteger('version')->default(1);
            $table->ulid('created_by')->nullable();
            $table->ulid('updated_by')->nullable();
            $table->timestamps();

            $table->index('server_name');
            $table->index(['server_name', 'username_hash']);
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
        return ((string) config('laranail.db-console.catalog.prefix', 'db_console_')).'accounts';
    }
};

<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Builder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

/**
 * Operator-security state: when each operator without two-factor was first
 * refused (the start of their enrolment grace window), and the break-glass
 * bypasses issued from the host's shell.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->schema()->create($this->table('two_factor_grace'), function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('assignee_type');
            $table->string('assignee_id');
            $table->timestamp('first_refused_at')->nullable();   // null until enforcement first refuses
            $table->timestamp('notified_at')->nullable();
            $table->timestamp('reported_at')->nullable();        // report mode's last would-refuse alert
            $table->timestamps();

            $table->unique(['assignee_type', 'assignee_id'], 'db_console_two_factor_grace_unique');
        });

        $this->schema()->create($this->table('security_bypasses'), function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('assignee_type');
            $table->string('assignee_id');
            $table->string('issued_by')->nullable();
            $table->timestamp('expires_at');
            $table->timestamp('step_up_used_at')->nullable();
            $table->timestamps();

            $table->index(['assignee_type', 'assignee_id'], 'db_console_security_bypasses_assignee');
        });
    }

    public function down(): void
    {
        $this->schema()->dropIfExists($this->table('security_bypasses'));
        $this->schema()->dropIfExists($this->table('two_factor_grace'));
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

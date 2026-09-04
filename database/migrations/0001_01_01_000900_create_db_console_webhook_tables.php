<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Builder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

/**
 * Webhook subscription + delivery tables (present when the API/webhooks are
 * used). The signing secret is stored by reference (secret_ref → SecretVault),
 * never in the clear.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->schema()->create($this->table('webhook_subscriptions'), function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('url', 2048);
            $table->json('events');
            $table->string('secret_ref');
            $table->boolean('active')->default(true);
            $table->string('server')->nullable();
            $table->unsignedInteger('failure_count')->default(0);
            $table->ulid('created_by')->nullable();
            $table->timestamps();

            $table->index('active');
        });

        $this->schema()->create($this->table('webhook_deliveries'), function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulid('subscription_id');
            $table->string('event');
            $table->string('payload_hash', 64);
            $table->unsignedSmallInteger('response_status')->nullable();
            $table->unsignedSmallInteger('attempt')->default(1);
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamps();

            $table->index('subscription_id');
        });
    }

    public function down(): void
    {
        $this->schema()->dropIfExists($this->table('webhook_deliveries'));
        $this->schema()->dropIfExists($this->table('webhook_subscriptions'));
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

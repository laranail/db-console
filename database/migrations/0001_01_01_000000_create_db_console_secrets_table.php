<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;
use Illuminate\Support\Facades\Schema;

/**
 * The SecretVault backing store, on the dedicated catalog connection. The
 * payload column holds whatever the active driver produced — ciphertext
 * (app_key), an envelope with a KMS-wrapped data key (kms), a Vault path,
 * or a bare external pointer (reference). Nothing plaintext-recoverable
 * lives here under kms/vault/reference; under app_key it is the
 * column-encrypted admin secret.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->schema()->create($this->table(), function (Blueprint $table): void {
            $table->string('ref')->primary();
            $table->text('payload');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        $this->schema()->dropIfExists($this->table());
    }

    private function schema(): Builder
    {
        return Schema::connection(
            (string) config('laranail.db-console.catalog.connection', 'db_console_catalog'),
        );
    }

    private function table(): string
    {
        return ((string) config('laranail.db-console.catalog.prefix', 'db_console_')).'secrets';
    }
};

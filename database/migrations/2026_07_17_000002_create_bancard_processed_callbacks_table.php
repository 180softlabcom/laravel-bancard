<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('bancard_processed_callbacks')) {
            return;
        }

        // Idempotencia del webhook, independiente de bancard_transactions: una fila por
        // shop_process_id. Liviana a propósito (no guarda montos/PII del pago) — el
        // registro de negocio completo es bancard_transactions (opt-in vía
        // persist_transactions). Ver BancardIdempotencyStore.
        Schema::create('bancard_processed_callbacks', function (Blueprint $table) {
            $table->id();
            $table->string('shop_process_id')->unique(); // clave de dedup
            // alias_token del charge: Bancard no lo manda en el callback, así que se guarda
            // al cobrar para validar el token del webhook sin depender de persist_transactions.
            $table->string('alias_token')->nullable();
            // null hasta que el PRIMER callback lo reclama (dedup atómico).
            $table->timestamp('claimed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bancard_processed_callbacks');
    }
};

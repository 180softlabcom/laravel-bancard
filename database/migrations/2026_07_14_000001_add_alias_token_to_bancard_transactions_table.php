<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // El token del webhook de confirmación de un charge (pago con token) se
        // firma con el alias_token, que NO viene en el payload del callback. Lo
        // persistimos al iniciar el charge para poder validar el token del webhook.
        if (Schema::hasTable('bancard_transactions') && ! Schema::hasColumn('bancard_transactions', 'alias_token')) {
            Schema::table('bancard_transactions', function (Blueprint $table) {
                $table->string('alias_token')->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('bancard_transactions', 'alias_token')) {
            Schema::table('bancard_transactions', function (Blueprint $table) {
                $table->dropColumn('alias_token');
            });
        }
    }
};

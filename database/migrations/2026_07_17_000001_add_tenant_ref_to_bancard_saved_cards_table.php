<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Scoping per-comercio de la tarjeta (multi-tenant): en multi-tenant una tarjeta
        // pertenece a (usuario, comercio). Nullable → single-tenant no lo usa. La columna
        // es configurable (bancard.saved_cards_tenant_column); un consumidor que ya tenga
        // su propia columna (p.ej. commerce_id) apunta la config ahí y NO necesita esta.
        $column = config('bancard.saved_cards_tenant_column', 'tenant_ref');

        if (Schema::hasTable('bancard_saved_cards') && ! Schema::hasColumn('bancard_saved_cards', $column)) {
            Schema::table('bancard_saved_cards', function (Blueprint $table) use ($column) {
                $table->string($column)->nullable()->index();
            });
        }
    }

    public function down(): void
    {
        // Solo dropeamos la columna que ESTE migration crea por default ('tenant_ref').
        // Si el consumidor reutiliza una columna propia (p.ej. commerce_id), up() NO la
        // creó → down() NO debe borrarla (destruiría datos ajenos en un rollback).
        if (Schema::hasColumn('bancard_saved_cards', 'tenant_ref')) {
            Schema::table('bancard_saved_cards', function (Blueprint $table) {
                $table->dropColumn('tenant_ref');
            });
        }
    }
};

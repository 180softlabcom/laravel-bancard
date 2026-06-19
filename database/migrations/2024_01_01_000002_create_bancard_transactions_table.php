<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bancard_transactions', function (Blueprint $table) {
            $table->id();
            $table->string('shop_process_id')->unique(); // clave de idempotencia/conciliación
            $table->string('process_id')->nullable();
            $table->string('type')->default('single_buy'); // single_buy | charge
            $table->string('status')->default('pending');  // pending | paid | failed | rolled_back
            $table->string('amount')->nullable();
            $table->string('currency', 3)->nullable();
            $table->nullableMorphs('payable'); // payable_type + payable_id
            $table->string('authorization_number')->nullable();
            $table->string('ticket_number')->nullable();
            $table->json('last_payload')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bancard_transactions');
    }
};

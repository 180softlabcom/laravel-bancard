<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('bancard_saved_cards', function (Blueprint $table) {
            $table->id();
            $table->string('user_type'); // Polymorphic type
            $table->unsignedBigInteger('user_id'); // Polymorphic ID
            $table->string('alias_token'); // Bancard token for charging
            $table->string('card_masked_number')->nullable(); // e.g., **** **** **** 1234
            $table->string('card_brand')->nullable(); // Visa, Mastercard, etc.
            $table->string('card_type')->nullable(); // credit, debit
            $table->string('expiration_date')->nullable(); // MM/YY format
            $table->integer('card_id')->nullable(); // Internal card ID used during registration
            $table->boolean('is_default')->default(false);
            $table->json('metadata')->nullable(); // Additional data
            $table->timestamps();

            // Indexes
            $table->index(['user_type', 'user_id']);
            $table->unique(['user_type', 'user_id', 'alias_token']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('bancard_saved_cards');
    }
};

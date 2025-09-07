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
        Schema::create('replay_nonces', function (Blueprint $table) {
            $table->id();
            $table->string('nonce', 255)->unique(); // Unique nonce value
            $table->string('api_key', 100)->nullable(); // Associated API key
            $table->timestamp('created_at');
            
            // Index for fast nonce lookups
            $table->index('nonce');
            $table->index('created_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('replay_nonces');
    }
};

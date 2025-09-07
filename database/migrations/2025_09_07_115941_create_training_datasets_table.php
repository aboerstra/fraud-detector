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
        Schema::create('training_datasets', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('version')->default('1.0.0');
            $table->text('description')->nullable();
            $table->string('file_path');
            $table->string('file_type')->default('csv'); // csv, json
            $table->bigInteger('file_size'); // in bytes
            $table->integer('record_count')->default(0);
            $table->json('metadata')->nullable(); // file stats, column info, etc.
            $table->json('quality_metrics')->nullable(); // data quality scores
            $table->enum('status', ['uploading', 'processing', 'ready', 'error'])->default('uploading');
            $table->text('error_message')->nullable();
            $table->string('uploaded_by')->nullable(); // user identifier
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            
            // Indexes
            $table->index(['status', 'is_active']);
            $table->index('created_at');
            $table->unique(['name', 'version']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('training_datasets');
    }
};

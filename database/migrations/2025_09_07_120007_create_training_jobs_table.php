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
        Schema::create('training_jobs', function (Blueprint $table) {
            $table->id();
            $table->string('job_id')->unique(); // UUID for tracking
            $table->foreignId('dataset_id')->constrained('training_datasets');
            $table->string('name');
            $table->text('description')->nullable();
            $table->json('config'); // training parameters, hyperparameters
            $table->enum('status', ['queued', 'running', 'completed', 'failed', 'cancelled'])->default('queued');
            $table->integer('progress')->default(0); // 0-100
            $table->text('status_message')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->integer('duration_seconds')->nullable();
            $table->json('metrics')->nullable(); // training metrics (accuracy, loss, etc.)
            $table->json('validation_results')->nullable(); // cross-validation results
            $table->json('feature_importance')->nullable();
            $table->string('model_path')->nullable(); // path to saved model
            $table->text('error_message')->nullable();
            $table->json('logs')->nullable(); // training logs
            $table->string('created_by')->nullable(); // user identifier
            $table->timestamps();
            
            // Indexes
            $table->index(['status', 'created_at']);
            $table->index('dataset_id');
            $table->index('job_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('training_jobs');
    }
};

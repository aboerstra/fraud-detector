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
        Schema::create('model_registry', function (Blueprint $table) {
            $table->id();
            $table->string('model_id')->unique(); // UUID for model identification
            $table->string('name');
            $table->string('version');
            $table->text('description')->nullable();
            $table->foreignId('training_job_id')->constrained('training_jobs');
            $table->string('algorithm')->default('lightgbm');
            $table->string('model_path'); // path to model file
            $table->bigInteger('model_size')->nullable(); // file size in bytes
            $table->json('hyperparameters')->nullable(); // final hyperparameters used
            $table->json('performance_metrics'); // precision, recall, f1, auc, etc.
            $table->json('validation_metrics')->nullable(); // cross-validation results
            $table->json('feature_importance')->nullable();
            $table->json('training_metadata')->nullable(); // training time, dataset info, etc.
            $table->enum('status', ['training', 'ready', 'deployed', 'archived', 'failed'])->default('training');
            $table->boolean('is_production')->default(false);
            $table->timestamp('deployed_at')->nullable();
            $table->timestamp('archived_at')->nullable();
            $table->json('deployment_config')->nullable(); // A/B test settings, etc.
            $table->string('created_by')->nullable(); // user identifier
            $table->timestamps();
            
            // Indexes
            $table->index(['status', 'is_production']);
            $table->index('training_job_id');
            $table->index('created_at');
            $table->unique(['name', 'version']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('model_registry');
    }
};

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
        Schema::create('training_metrics', function (Blueprint $table) {
            $table->id();
            $table->foreignId('training_job_id')->constrained('training_jobs');
            $table->foreignId('model_id')->nullable()->constrained('model_registry');
            $table->string('metric_type'); // 'training', 'validation', 'test', 'production'
            $table->integer('epoch')->nullable(); // for tracking during training
            $table->decimal('precision', 5, 4)->nullable();
            $table->decimal('recall', 5, 4)->nullable();
            $table->decimal('f1_score', 5, 4)->nullable();
            $table->decimal('accuracy', 5, 4)->nullable();
            $table->decimal('auc_roc', 5, 4)->nullable();
            $table->decimal('auc_pr', 5, 4)->nullable(); // precision-recall AUC
            $table->decimal('log_loss', 8, 6)->nullable();
            $table->json('confusion_matrix')->nullable(); // [[TP, FP], [FN, TN]]
            $table->json('classification_report')->nullable(); // detailed per-class metrics
            $table->json('business_metrics')->nullable(); // cost savings, review reduction, etc.
            $table->json('custom_metrics')->nullable(); // any additional metrics
            $table->timestamp('calculated_at');
            $table->timestamps();
            
            // Indexes
            $table->index(['training_job_id', 'metric_type']);
            $table->index(['model_id', 'calculated_at']);
            $table->index('calculated_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('training_metrics');
    }
};

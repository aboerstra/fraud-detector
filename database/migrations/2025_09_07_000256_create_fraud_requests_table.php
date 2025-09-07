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
        Schema::create('fraud_requests', function (Blueprint $table) {
            $table->uuid('id')->primary();
            
            // Request metadata
            $table->json('application_data'); // Encrypted PII data
            $table->string('client_ip', 45); // IPv4/IPv6 support
            $table->text('user_agent')->nullable();
            $table->enum('status', ['queued', 'processing', 'decided', 'failed'])->default('queued');
            $table->timestamp('submitted_at');
            $table->timestamp('decided_at')->nullable();
            
            // Decision results
            $table->enum('final_decision', ['approve', 'review', 'decline'])->nullable();
            $table->json('decision_reasons')->nullable(); // Array of reason strings
            
            // Scoring results
            $table->decimal('rule_score', 5, 4)->nullable(); // 0.0000 to 1.0000
            $table->decimal('confidence_score', 5, 4)->nullable(); // 0.0000 to 1.0000
            $table->decimal('adjudicator_score', 5, 4)->nullable(); // 0.0000 to 1.0000
            
            // Explainability data
            $table->json('rule_flags')->nullable(); // Array of triggered rule flags
            $table->json('top_features')->nullable(); // Array of feature importance
            $table->json('adjudicator_rationale')->nullable(); // Array of LLM reasoning
            
            // Error handling
            $table->text('error_message')->nullable();
            
            // Version tracking
            $table->string('rulepack_version', 50)->nullable();
            $table->string('feature_set_version', 50)->nullable();
            $table->string('model_version', 50)->nullable();
            $table->string('policy_version', 50)->nullable();
            
            $table->timestamps();
            
            // Indexes for performance
            $table->index('status');
            $table->index('submitted_at');
            $table->index('decided_at');
            $table->index('final_decision');
            $table->index(['status', 'submitted_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('fraud_requests');
    }
};

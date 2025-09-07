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
        Schema::create('training_labels', function (Blueprint $table) {
            $table->id();
            $table->foreignId('dataset_id')->constrained('training_datasets');
            $table->string('record_id'); // identifier for the specific record in dataset
            $table->json('application_data'); // the actual application data
            $table->boolean('fraud_label'); // true = fraud, false = legitimate
            $table->decimal('confidence', 3, 2)->default(1.00); // 0.00 to 1.00
            $table->enum('label_source', ['manual', 'historical', 'expert', 'automated'])->default('manual');
            $table->text('notes')->nullable(); // reasoning for the label
            $table->string('labeled_by')->nullable(); // user identifier
            $table->timestamp('labeled_at')->nullable();
            $table->boolean('is_validated')->default(false); // has been reviewed/confirmed
            $table->string('validated_by')->nullable();
            $table->timestamp('validated_at')->nullable();
            $table->timestamps();
            
            // Indexes
            $table->index(['dataset_id', 'fraud_label']);
            $table->index(['is_validated', 'labeled_at']);
            $table->unique(['dataset_id', 'record_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('training_labels');
    }
};

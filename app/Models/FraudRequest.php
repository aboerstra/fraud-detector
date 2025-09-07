<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class FraudRequest extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'application_data',
        'client_ip',
        'user_agent',
        'status',
        'submitted_at',
        'decided_at',
        'final_decision',
        'decision_reasons',
        'rule_score',
        'confidence_score',
        'adjudicator_score',
        'rule_flags',
        'top_features',
        'adjudicator_rationale',
        'error_message',
        'rulepack_version',
        'feature_set_version',
        'model_version',
        'policy_version',
    ];

    protected $casts = [
        'application_data' => 'array',
        'decision_reasons' => 'array',
        'rule_flags' => 'array',
        'top_features' => 'array',
        'adjudicator_rationale' => 'array',
        'submitted_at' => 'datetime',
        'decided_at' => 'datetime',
        'rule_score' => 'float',
        'confidence_score' => 'float',
        'adjudicator_score' => 'float',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'application_data', // Hide PII from serialization
    ];

    /**
     * Get the route key for the model.
     */
    public function getRouteKeyName(): string
    {
        return 'id';
    }

    /**
     * Scope a query to only include requests with a specific status.
     */
    public function scopeWithStatus($query, string $status)
    {
        return $query->where('status', $status);
    }

    /**
     * Scope a query to only include decided requests.
     */
    public function scopeDecided($query)
    {
        return $query->where('status', 'decided');
    }

    /**
     * Scope a query to only include failed requests.
     */
    public function scopeFailed($query)
    {
        return $query->where('status', 'failed');
    }

    /**
     * Get the processing time in milliseconds.
     */
    public function getProcessingTimeAttribute(): ?int
    {
        if (!$this->decided_at || !$this->submitted_at) {
            return null;
        }

        return $this->submitted_at->diffInMilliseconds($this->decided_at);
    }

    /**
     * Check if the request is complete (decided or failed).
     */
    public function isComplete(): bool
    {
        return in_array($this->status, ['decided', 'failed']);
    }

    /**
     * Check if the request is still processing.
     */
    public function isProcessing(): bool
    {
        return in_array($this->status, ['queued', 'processing']);
    }

    /**
     * Mark the request as processing.
     */
    public function markAsProcessing(): void
    {
        $this->update(['status' => 'processing']);
    }

    /**
     * Mark the request as decided with the final decision.
     */
    public function markAsDecided(
        string $finalDecision,
        array $reasons,
        ?float $ruleScore = null,
        ?float $confidenceScore = null,
        ?float $adjudicatorScore = null,
        array $ruleFlags = [],
        array $topFeatures = [],
        array $adjudicatorRationale = [],
        ?string $rulepackVersion = null,
        ?string $featureSetVersion = null,
        ?string $modelVersion = null,
        ?string $policyVersion = null
    ): void {
        $this->update([
            'status' => 'decided',
            'decided_at' => now(),
            'final_decision' => $finalDecision,
            'decision_reasons' => $reasons,
            'rule_score' => $ruleScore,
            'confidence_score' => $confidenceScore,
            'adjudicator_score' => $adjudicatorScore,
            'rule_flags' => $ruleFlags,
            'top_features' => $topFeatures,
            'adjudicator_rationale' => $adjudicatorRationale,
            'rulepack_version' => $rulepackVersion,
            'feature_set_version' => $featureSetVersion,
            'model_version' => $modelVersion,
            'policy_version' => $policyVersion,
        ]);
    }

    /**
     * Mark the request as failed with an error message.
     */
    public function markAsFailed(string $errorMessage): void
    {
        $this->update([
            'status' => 'failed',
            'decided_at' => now(),
            'error_message' => $errorMessage,
        ]);
    }

    /**
     * Get redacted application data (without PII) for ML/LLM processing.
     */
    public function getRedactedData(): array
    {
        $data = $this->application_data;
        
        // Remove or redact PII fields
        unset($data['personal_info']['sin']);
        unset($data['contact_info']['email']);
        unset($data['contact_info']['phone']);
        unset($data['contact_info']['address']);
        
        // Keep only non-PII fields for processing
        return [
            'age' => $this->calculateAge($data['personal_info']['date_of_birth'] ?? null),
            'province' => $data['personal_info']['province'] ?? null,
            'annual_income' => $data['financial_info']['annual_income'] ?? null,
            'employment_status' => $data['financial_info']['employment_status'] ?? null,
            'loan_amount' => $data['loan_info']['amount'] ?? null,
            'loan_term_months' => $data['loan_info']['term_months'] ?? null,
            'down_payment' => $data['loan_info']['down_payment'] ?? null,
            'vehicle_year' => $data['vehicle_info']['year'] ?? null,
            'vehicle_make' => $data['vehicle_info']['make'] ?? null,
            'vehicle_model' => $data['vehicle_info']['model'] ?? null,
            'vehicle_mileage' => $data['vehicle_info']['mileage'] ?? null,
            'vehicle_value' => $data['vehicle_info']['value'] ?? null,
            'dealer_id' => $data['dealer_info']['dealer_id'] ?? null,
            'dealer_location' => $data['dealer_info']['location'] ?? null,
        ];
    }

    /**
     * Calculate age from date of birth.
     */
    private function calculateAge(?string $dateOfBirth): ?int
    {
        if (!$dateOfBirth) {
            return null;
        }

        try {
            return \Carbon\Carbon::parse($dateOfBirth)->age;
        } catch (\Exception $e) {
            return null;
        }
    }
}

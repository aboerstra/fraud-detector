<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use App\Models\FraudRequest;

class FraudDetectionJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 300; // 5 minutes
    public $tries = 3;
    public $backoff = [30, 60, 120]; // Exponential backoff in seconds

    protected string $fraudRequestId;

    /**
     * Create a new job instance.
     */
    public function __construct(string $fraudRequestId)
    {
        $this->fraudRequestId = $fraudRequestId;
        // Use default queue for now to ensure jobs are processed
        // $this->onQueue('fraud-detection');
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $startTime = microtime(true);
        
        try {
            Log::info('Starting fraud detection pipeline', [
                'job_id' => $this->fraudRequestId,
                'attempt' => $this->attempts(),
            ]);

            // Load the fraud request
            $fraudRequest = FraudRequest::findOrFail($this->fraudRequestId);
            $fraudRequest->markAsProcessing();

            // Execute the fraud detection pipeline
            $this->executePipeline($fraudRequest);

            $processingTime = (microtime(true) - $startTime) * 1000;
            Log::info('Fraud detection pipeline completed', [
                'job_id' => $this->fraudRequestId,
                'processing_time_ms' => $processingTime,
                'final_decision' => $fraudRequest->fresh()->final_decision,
            ]);

        } catch (\Exception $e) {
            $this->handleFailure($e);
        }
    }

    /**
     * Execute the fraud detection pipeline
     */
    private function executePipeline(FraudRequest $fraudRequest): void
    {
        // Stage 1: Rules Processing
        $rulesResult = $this->processRules($fraudRequest);
        
        // Check for hard-fail rules (immediate decline)
        if ($rulesResult['hard_fail']) {
            $fraudRequest->markAsDecided(
                'decline',
                $rulesResult['reasons'],
                $rulesResult['score'],
                null,
                null,
                $rulesResult['flags'],
                [],
                [],
                $rulesResult['version']
            );
            return;
        }

        // Stage 2: Feature Engineering
        $features = $this->extractFeatures($fraudRequest);

        // Stage 3: ML Scoring
        $mlResult = $this->scoreMlModel($fraudRequest, $features);

        // Stage 4: LLM Adjudication (if needed)
        $adjudicatorResult = $this->adjudicateWithLlm($fraudRequest, $rulesResult, $mlResult);

        // Stage 5: Decision Assembly
        $finalDecision = $this->assembleDecision($rulesResult, $mlResult, $adjudicatorResult);

        // Save final decision
        $fraudRequest->markAsDecided(
            $finalDecision['decision'],
            $finalDecision['reasons'],
            $rulesResult['score'],
            $mlResult['confidence_score'],
            $adjudicatorResult['score'],
            $rulesResult['flags'],
            $mlResult['top_features'],
            $adjudicatorResult['rationale'],
            $rulesResult['version'],
            $features['version'],
            $mlResult['model_version'],
            $finalDecision['policy_version']
        );
    }

    /**
     * Process business rules
     */
    private function processRules(FraudRequest $fraudRequest): array
    {
        Log::info('Processing rules', ['job_id' => $this->fraudRequestId]);

        // For now, implement basic mock rules
        // TODO: Implement actual rules engine in Phase 4
        $applicationData = $fraudRequest->application_data;
        
        $flags = [];
        $score = 0.0;
        $hardFail = false;
        $reasons = [];

        // Mock rule: Check SIN format
        if (!$this->isValidSin($applicationData['personal_info']['sin'] ?? '')) {
            $flags[] = 'invalid_sin';
            $hardFail = true;
            $reasons[] = 'Invalid SIN format detected';
        }

        // Mock rule: Check age
        $age = $this->calculateAge($applicationData['personal_info']['date_of_birth'] ?? null);
        if ($age && $age < 18) {
            $flags[] = 'underage_applicant';
            $hardFail = true;
            $reasons[] = 'Applicant is under 18 years old';
        }

        // Mock rule: Check loan-to-value ratio
        $loanAmount = $applicationData['loan_info']['amount'] ?? 0;
        $vehicleValue = $applicationData['vehicle_info']['value'] ?? 1;
        $ltvRatio = $vehicleValue > 0 ? $loanAmount / $vehicleValue : 1;
        
        if ($ltvRatio > 1.2) {
            $flags[] = 'high_ltv_ratio';
            $score += 0.3;
            $reasons[] = 'High loan-to-value ratio detected';
        }

        // Mock rule: Check income vs loan amount
        $income = $applicationData['financial_info']['annual_income'] ?? 0;
        if ($income > 0 && ($loanAmount / $income) > 0.5) {
            $flags[] = 'high_debt_to_income';
            $score += 0.2;
            $reasons[] = 'High debt-to-income ratio';
        }

        return [
            'hard_fail' => $hardFail,
            'score' => min($score, 1.0),
            'flags' => $flags,
            'reasons' => $reasons,
            'version' => 'v1.0.0-mock',
        ];
    }

    /**
     * Extract features for ML model
     */
    private function extractFeatures(FraudRequest $fraudRequest): array
    {
        Log::info('Extracting features', ['job_id' => $this->fraudRequestId]);

        // TODO: Implement actual feature engineering in Phase 5
        $redactedData = $fraudRequest->getRedactedData();
        
        // Mock feature extraction
        $features = [
            'age' => $redactedData['age'] ?? 0,
            'ltv_ratio' => $this->calculateLtvRatio($redactedData),
            'debt_to_income' => $this->calculateDebtToIncome($redactedData),
            'vehicle_age' => date('Y') - ($redactedData['vehicle_year'] ?? date('Y')),
            'down_payment_ratio' => $this->calculateDownPaymentRatio($redactedData),
        ];

        return [
            'features' => $features,
            'version' => 'v1.0.0-mock',
        ];
    }

    /**
     * Score with ML model
     */
    private function scoreMlModel(FraudRequest $fraudRequest, array $features): array
    {
        Log::info('Scoring with ML model', ['job_id' => $this->fraudRequestId]);

        // TODO: Implement actual ML service call in Phase 6
        // Mock ML scoring based on simple rules
        $featureData = $features['features'];
        
        $riskScore = 0.0;
        
        // Simple risk scoring logic
        if ($featureData['age'] < 25) $riskScore += 0.1;
        if ($featureData['ltv_ratio'] > 0.9) $riskScore += 0.2;
        if ($featureData['debt_to_income'] > 0.4) $riskScore += 0.2;
        if ($featureData['vehicle_age'] > 10) $riskScore += 0.1;
        if ($featureData['down_payment_ratio'] < 0.1) $riskScore += 0.15;

        $confidenceScore = min($riskScore, 1.0);

        return [
            'confidence_score' => $confidenceScore,
            'top_features' => [
                ['feature_name' => 'ltv_ratio', 'importance' => 0.25],
                ['feature_name' => 'debt_to_income', 'importance' => 0.20],
                ['feature_name' => 'age', 'importance' => 0.15],
            ],
            'model_version' => 'v1.0.0-mock',
        ];
    }

    /**
     * Adjudicate with LLM
     */
    private function adjudicateWithLlm(FraudRequest $fraudRequest, array $rulesResult, array $mlResult): array
    {
        Log::info('Adjudicating with LLM', ['job_id' => $this->fraudRequestId]);

        // LLM adjudication implemented with OpenRouter/Claude Sonnet 4
        $combinedScore = ($rulesResult['score'] + $mlResult['confidence_score']) / 2;
        
        $adjudicatorScore = $combinedScore;
        $rationale = [];

        if ($combinedScore < 0.3) {
            $rationale[] = 'Low risk profile based on rules and ML analysis';
            $rationale[] = 'Standard application with no concerning patterns';
        } elseif ($combinedScore < 0.7) {
            $rationale[] = 'Moderate risk profile requiring review';
            $rationale[] = 'Some risk factors present but within acceptable range';
        } else {
            $rationale[] = 'High risk profile detected';
            $rationale[] = 'Multiple risk factors indicate potential fraud';
        }

        return [
            'score' => $adjudicatorScore,
            'rationale' => $rationale,
            'version' => 'v1.0.0-mock',
        ];
    }

    /**
     * Assemble final decision
     */
    private function assembleDecision(array $rulesResult, array $mlResult, array $adjudicatorResult): array
    {
        Log::info('Assembling final decision', ['job_id' => $this->fraudRequestId]);

        // TODO: Implement actual decision policy in Phase 8
        // Mock decision logic
        $ruleScore = $rulesResult['score'];
        $mlScore = $mlResult['confidence_score'];
        $adjudicatorScore = $adjudicatorResult['score'];

        $reasons = [];
        $decision = 'approve';

        // Decision thresholds (mock)
        if ($ruleScore >= 0.8 || $mlScore >= 0.85 || $adjudicatorScore >= 0.8) {
            $decision = 'decline';
            $reasons[] = 'High fraud risk detected across multiple scoring methods';
        } elseif ($ruleScore >= 0.6 || $mlScore >= 0.7 || $adjudicatorScore >= 0.7) {
            $decision = 'review';
            $reasons[] = 'Moderate fraud risk requires manual review';
        } else {
            $reasons[] = 'Low fraud risk - application approved';
        }

        // Add specific reasons from each stage
        $reasons = array_merge($reasons, $rulesResult['reasons']);

        return [
            'decision' => $decision,
            'reasons' => array_unique($reasons),
            'policy_version' => 'v1.0.0-mock',
        ];
    }

    /**
     * Handle job failure
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('Fraud detection job failed', [
            'job_id' => $this->fraudRequestId,
            'error' => $exception->getMessage(),
            'trace' => $exception->getTraceAsString(),
        ]);

        try {
            $fraudRequest = FraudRequest::find($this->fraudRequestId);
            if ($fraudRequest) {
                $fraudRequest->markAsFailed($exception->getMessage());
            }
        } catch (\Exception $e) {
            Log::error('Failed to mark fraud request as failed', [
                'job_id' => $this->fraudRequestId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Handle failure during execution
     */
    private function handleFailure(\Exception $exception): void
    {
        Log::error('Error in fraud detection pipeline', [
            'job_id' => $this->fraudRequestId,
            'error' => $exception->getMessage(),
            'attempt' => $this->attempts(),
        ]);

        if ($this->attempts() >= $this->tries) {
            $this->failed($exception);
        } else {
            throw $exception; // Let Laravel retry
        }
    }

    // Helper methods
    private function isValidSin(string $sin): bool
    {
        return preg_match('/^\d{9}$/', $sin) === 1;
    }

    private function calculateAge(?string $dateOfBirth): ?int
    {
        if (!$dateOfBirth) return null;
        try {
            return \Carbon\Carbon::parse($dateOfBirth)->age;
        } catch (\Exception $e) {
            return null;
        }
    }

    private function calculateLtvRatio(array $data): float
    {
        $loanAmount = $data['loan_amount'] ?? 0;
        $vehicleValue = $data['vehicle_value'] ?? 1;
        return $vehicleValue > 0 ? $loanAmount / $vehicleValue : 1.0;
    }

    private function calculateDebtToIncome(array $data): float
    {
        $loanAmount = $data['loan_amount'] ?? 0;
        $income = $data['annual_income'] ?? 1;
        return $income > 0 ? $loanAmount / $income : 1.0;
    }

    private function calculateDownPaymentRatio(array $data): float
    {
        $downPayment = $data['down_payment'] ?? 0;
        $vehicleValue = $data['vehicle_value'] ?? 1;
        return $vehicleValue > 0 ? $downPayment / $vehicleValue : 0.0;
    }
}

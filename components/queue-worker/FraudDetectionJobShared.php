<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use App\Models\FraudRequest;
use App\Components\RulesEngine\RulesEngine;
use App\Components\FeatureEngineering\FeatureExtractor;
use Components\LLMAdjudicator\LLMAdjudicator;

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
        $this->onQueue('fraud-detection');
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
                $rulesResult['hard_fail_reasons'],
                $rulesResult['risk_score'],
                null,
                null,
                $rulesResult['hard_fail_reasons'],
                [],
                [],
                'rules_v1.0.0'
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
            $rulesResult['risk_score'],
            $mlResult['confidence_score'],
            $adjudicatorResult['score'],
            $rulesResult['risk_factors'],
            $mlResult['top_features'],
            $adjudicatorResult['rationale'],
            'rules_v1.0.0',
            $features['version'],
            $mlResult['model_version'],
            $finalDecision['policy_version']
        );
    }

    /**
     * Process business rules using the actual Rules Engine
     */
    private function processRules(FraudRequest $fraudRequest): array
    {
        Log::info('Processing rules with Rules Engine', ['job_id' => $this->fraudRequestId]);

        try {
            $rulesEngine = new RulesEngine();
            $result = $rulesEngine->evaluate($fraudRequest);

            return [
                'hard_fail' => $result['hard_fail'],
                'hard_fail_reasons' => $result['hard_fail_reasons'],
                'risk_score' => $result['risk_score'] / 100, // Convert to 0-1 scale
                'risk_factors' => $result['risk_factors'],
                'rules_applied' => $result['rules_applied'],
                'processing_time_ms' => $result['processing_time_ms'],
                'version' => 'rules_v1.0.0',
            ];

        } catch (\Exception $e) {
            Log::error('Rules Engine failed, falling back to mock', [
                'job_id' => $this->fraudRequestId,
                'error' => $e->getMessage(),
            ]);

            // Fallback to mock implementation
            return $this->mockRulesProcessing($fraudRequest);
        }
    }

    /**
     * Extract features using the actual Feature Engineering component
     */
    private function extractFeatures(FraudRequest $fraudRequest): array
    {
        Log::info('Extracting features with Feature Extractor', ['job_id' => $this->fraudRequestId]);

        try {
            $featureExtractor = new FeatureExtractor();
            $result = $featureExtractor->extractFeatures($fraudRequest);

            return [
                'features' => $result['features'],
                'feature_vector' => $result['feature_vector'],
                'feature_names' => $result['feature_names'],
                'extractor_results' => $result['extractor_results'],
                'processing_time_ms' => $result['processing_time_ms'],
                'version' => 'features_v1.0.0',
            ];

        } catch (\Exception $e) {
            Log::error('Feature Extractor failed, falling back to mock', [
                'job_id' => $this->fraudRequestId,
                'error' => $e->getMessage(),
            ]);

            // Fallback to mock implementation
            return $this->mockFeatureExtraction($fraudRequest);
        }
    }

    /**
     * Score with ML model using actual ML service
     */
    private function scoreMlModel(FraudRequest $fraudRequest, array $features): array
    {
        Log::info('Scoring with ML model', ['job_id' => $this->fraudRequestId]);

        try {
            // Check if ML service is enabled
            if (!config('services.ml_service.enabled', true)) {
                Log::info('ML service disabled, using fallback scoring');
                return $this->mockMlScoring($features);
            }

            // Call actual ML service
            $mlServiceUrl = config('services.ml_service.url');
            $timeout = config('services.ml_service.timeout', 30);
            $apiKey = config('services.ml_service.api_key');

            // Prepare request payload
            $payload = [
                'request_id' => $this->fraudRequestId,
                'feature_vector' => $features['feature_vector'] ?? [],
                'model_version' => 'latest',
                'include_explanations' => true
            ];

            // Set up HTTP headers
            $headers = [
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ];

            if ($apiKey) {
                $headers['X-API-Key'] = $apiKey;
            }

            // Make HTTP request to ML service
            $response = \Http::timeout($timeout)
                ->withHeaders($headers)
                ->post($mlServiceUrl . '/predict', $payload);

            if ($response->successful()) {
                $data = $response->json();
                
                Log::info('ML service prediction successful', [
                    'job_id' => $this->fraudRequestId,
                    'fraud_probability' => $data['fraud_probability'] ?? 0,
                    'processing_time_ms' => $data['processing_time_ms'] ?? 0
                ]);

                return [
                    'confidence_score' => $data['confidence_score'] ?? 0.5,
                    'fraud_probability' => $data['fraud_probability'] ?? 0.5,
                    'risk_tier' => $data['risk_tier'] ?? 'medium',
                    'top_features' => $this->formatFeatureImportance($data['feature_importance'] ?? []),
                    'model_version' => $data['model_version'] ?? 'unknown',
                    'processing_time_ms' => $data['processing_time_ms'] ?? 0,
                ];
            } else {
                Log::warning('ML service request failed', [
                    'job_id' => $this->fraudRequestId,
                    'status_code' => $response->status(),
                    'response' => $response->body()
                ]);
                
                return $this->mockMlScoring($features);
            }

        } catch (\Exception $e) {
            Log::error('ML service call failed', [
                'job_id' => $this->fraudRequestId,
                'error' => $e->getMessage()
            ]);

            // Fallback to mock scoring
            return $this->mockMlScoring($features);
        }
    }

    /**
     * Mock ML scoring fallback
     */
    private function mockMlScoring(array $features): array
    {
        $featureVector = $features['feature_vector'] ?? [];
        
        // Simple risk scoring based on key features
        $riskScore = 0.0;
        
        if (count($featureVector) >= 15) {
            $creditScore = $featureVector[0] ?? 650;
            $debtToIncomeRatio = $featureVector[1] ?? 35;
            $loanToValueRatio = $featureVector[2] ?? 85;
            $employmentMonths = $featureVector[3] ?? 24;
            $delinquencies = $featureVector[7] ?? 0;

            // Calculate risk based on key features
            if ($creditScore < 600) $riskScore += 0.3;
            elseif ($creditScore < 650) $riskScore += 0.2;
            elseif ($creditScore < 700) $riskScore += 0.1;

            if ($debtToIncomeRatio > 50) $riskScore += 0.25;
            elseif ($debtToIncomeRatio > 40) $riskScore += 0.15;
            elseif ($debtToIncomeRatio > 30) $riskScore += 0.05;

            if ($loanToValueRatio > 100) $riskScore += 0.2;
            elseif ($loanToValueRatio > 90) $riskScore += 0.1;

            if ($employmentMonths < 6) $riskScore += 0.15;
            elseif ($employmentMonths < 12) $riskScore += 0.08;

            if ($delinquencies > 0) $riskScore += $delinquencies * 0.1;
        }

        $confidenceScore = min($riskScore, 1.0);

        return [
            'confidence_score' => $confidenceScore,
            'fraud_probability' => $confidenceScore,
            'risk_tier' => $confidenceScore > 0.7 ? 'high' : ($confidenceScore > 0.4 ? 'medium' : 'low'),
            'top_features' => [
                ['feature_name' => 'credit_score', 'importance' => 0.30],
                ['feature_name' => 'debt_to_income_ratio', 'importance' => 0.25],
                ['feature_name' => 'loan_to_value_ratio', 'importance' => 0.20],
                ['feature_name' => 'employment_months', 'importance' => 0.15],
                ['feature_name' => 'delinquencies_24m', 'importance' => 0.10],
            ],
            'model_version' => 'lightgbm_v1.0.0-mock',
        ];
    }

    /**
     * Format feature importance from ML service response
     */
    private function formatFeatureImportance(array $featureImportance): array
    {
        $formatted = [];
        
        foreach ($featureImportance as $feature) {
            $formatted[] = [
                'feature_name' => $feature['feature_name'] ?? 'unknown',
                'importance' => $feature['importance'] ?? 0.0,
                'value' => $feature['value'] ?? null
            ];
        }
        
        return $formatted;
    }

    /**
     * Adjudicate with LLM using real OpenRouter/Claude integration
     */
    private function adjudicateWithLlm(FraudRequest $fraudRequest, array $rulesResult, array $mlResult): array
    {
        Log::info('Adjudicating with LLM', ['job_id' => $this->fraudRequestId]);

        try {
            // Initialize LLM Adjudicator
            $llmAdjudicator = new LLMAdjudicator();

            // Check if adjudication should be triggered
            if (!$llmAdjudicator->shouldTriggerAdjudication($mlResult)) {
                Log::info('LLM adjudication not triggered - using ML results', [
                    'job_id' => $this->fraudRequestId,
                    'ml_fraud_probability' => $mlResult['fraud_probability'] ?? null,
                    'ml_confidence' => $mlResult['confidence_score'] ?? null
                ]);

                // Return simplified result based on ML
                return $this->buildFallbackAdjudication($rulesResult, $mlResult, 'threshold_not_met');
            }

            // Build context for LLM analysis
            $context = [
                'request_id' => $this->fraudRequestId,
                'application_data' => $fraudRequest->application_data,
                'rules_results' => [
                    'violations' => $this->formatRulesViolations($rulesResult),
                    'risk_score' => $rulesResult['risk_score'],
                    'risk_factors' => $rulesResult['risk_factors'] ?? []
                ],
                'ml_results' => [
                    'fraud_probability' => $mlResult['fraud_probability'] ?? null,
                    'confidence_score' => $mlResult['confidence_score'] ?? null,
                    'risk_tier' => $mlResult['risk_tier'] ?? 'unknown',
                    'feature_importance' => $mlResult['top_features'] ?? []
                ]
            ];

            // Perform LLM adjudication
            $result = $llmAdjudicator->adjudicate($context);

            if ($result['success']) {
                $analysis = $result['analysis'];
                
                Log::info('LLM adjudication successful', [
                    'job_id' => $this->fraudRequestId,
                    'fraud_probability' => $analysis['fraud_probability'],
                    'confidence' => $analysis['confidence'],
                    'recommendation' => $analysis['recommendation'],
                    'processing_time_ms' => $result['processing_time_ms']
                ]);

                return [
                    'score' => $analysis['fraud_probability'],
                    'assessment' => $this->mapRiskTierToAssessment($analysis['risk_tier']),
                    'confidence' => $analysis['confidence'],
                    'recommendation' => $analysis['recommendation'],
                    'rationale' => $this->formatLlmRationale($analysis),
                    'primary_concerns' => $analysis['primary_concerns'] ?? [],
                    'red_flags' => $analysis['red_flags'] ?? [],
                    'mitigating_factors' => $analysis['mitigating_factors'] ?? [],
                    'reasoning' => $analysis['reasoning'] ?? '',
                    'version' => 'llm_adjudicator_v1.0.0',
                    'model_used' => $result['model_used'] ?? 'unknown',
                    'provider' => $result['provider'] ?? 'unknown',
                    'processing_time_ms' => $result['processing_time_ms']
                ];

            } else {
                Log::warning('LLM adjudication failed, using fallback', [
                    'job_id' => $this->fraudRequestId,
                    'error' => $result['error'] ?? 'unknown'
                ]);

                return $this->buildFallbackAdjudication($rulesResult, $mlResult, 'llm_failed');
            }

        } catch (\Exception $e) {
            Log::error('LLM adjudication exception, using fallback', [
                'job_id' => $this->fraudRequestId,
                'error' => $e->getMessage()
            ]);

            return $this->buildFallbackAdjudication($rulesResult, $mlResult, 'exception');
        }
    }

    /**
     * Format rules violations for LLM context
     */
    private function formatRulesViolations(array $rulesResult): array
    {
        $violations = [];

        if (!empty($rulesResult['hard_fail_reasons'])) {
            foreach ($rulesResult['hard_fail_reasons'] as $reason) {
                $violations[] = [
                    'rule' => 'Hard Fail Rule',
                    'reason' => $reason,
                    'severity' => 'critical'
                ];
            }
        }

        if (!empty($rulesResult['risk_factors'])) {
            foreach ($rulesResult['risk_factors'] as $factor) {
                $violations[] = [
                    'rule' => $factor['rule'] ?? 'Risk Factor',
                    'reason' => $factor['description'] ?? $factor['factor'] ?? 'Unknown risk factor',
                    'severity' => $factor['severity'] ?? 'medium'
                ];
            }
        }

        return $violations;
    }

    /**
     * Map LLM risk tier to assessment
     */
    private function mapRiskTierToAssessment(string $riskTier): string
    {
        return match($riskTier) {
            'low' => 'low_risk',
            'medium' => 'moderate_risk',
            'high' => 'high_risk',
            default => 'moderate_risk'
        };
    }

    /**
     * Format LLM rationale for storage
     */
    private function formatLlmRationale(array $analysis): array
    {
        $rationale = [];

        // Add main reasoning
        if (!empty($analysis['reasoning'])) {
            $rationale[] = $analysis['reasoning'];
        }

        // Add primary concerns
        if (!empty($analysis['primary_concerns'])) {
            $rationale[] = 'Primary concerns: ' . implode(', ', $analysis['primary_concerns']);
        }

        // Add red flags
        if (!empty($analysis['red_flags'])) {
            $rationale[] = 'Red flags identified: ' . implode(', ', $analysis['red_flags']);
        }

        // Add mitigating factors
        if (!empty($analysis['mitigating_factors'])) {
            $rationale[] = 'Mitigating factors: ' . implode(', ', $analysis['mitigating_factors']);
        }

        return $rationale;
    }

    /**
     * Build fallback adjudication when LLM is unavailable
     */
    private function buildFallbackAdjudication(array $rulesResult, array $mlResult, string $reason): array
    {
        $combinedScore = ($rulesResult['risk_score'] + ($mlResult['confidence_score'] ?? 0.5)) / 2;
        
        $rationale = ["Fallback adjudication used (reason: {$reason})"];

        if ($combinedScore < 0.3) {
            $rationale[] = 'Low risk profile based on rules and ML analysis';
            $assessment = 'low_risk';
            $recommendation = 'approve';
        } elseif ($combinedScore < 0.7) {
            $rationale[] = 'Moderate risk profile with some concerning factors';
            $assessment = 'moderate_risk';
            $recommendation = 'review';
        } else {
            $rationale[] = 'High risk profile with multiple red flags';
            $assessment = 'high_risk';
            $recommendation = 'decline';
        }

        // Add specific insights from rules and ML
        if (!empty($rulesResult['risk_factors'])) {
            $rationale[] = 'Rules engine identified: ' . count($rulesResult['risk_factors']) . ' risk factors';
        }

        if (($mlResult['risk_tier'] ?? '') === 'high') {
            $rationale[] = 'ML model classified as high-risk application';
        }

        return [
            'score' => $combinedScore,
            'assessment' => $assessment,
            'confidence' => 0.75, // Lower confidence for fallback
            'recommendation' => $recommendation,
            'rationale' => $rationale,
            'primary_concerns' => [],
            'red_flags' => [],
            'mitigating_factors' => [],
            'reasoning' => "Fallback adjudication based on rules and ML scoring (LLM unavailable: {$reason})",
            'version' => 'fallback_adjudicator_v1.0.0',
            'fallback_reason' => $reason
        ];
    }

    /**
     * Assemble final decision
     */
    private function assembleDecision(array $rulesResult, array $mlResult, array $adjudicatorResult): array
    {
        Log::info('Assembling final decision', ['job_id' => $this->fraudRequestId]);

        $ruleScore = $rulesResult['risk_score'];
        $mlScore = $mlResult['confidence_score'];
        $adjudicatorScore = $adjudicatorResult['score'];

        $reasons = [];
        $decision = 'approve';

        // Decision thresholds based on combined scoring
        if ($ruleScore >= 0.8 || $mlScore >= 0.85 || $adjudicatorScore >= 0.8) {
            $decision = 'decline';
            $reasons[] = 'High fraud risk detected across multiple scoring methods';
        } elseif ($ruleScore >= 0.5 || $mlScore >= 0.6 || $adjudicatorScore >= 0.6) {
            $decision = 'review';
            $reasons[] = 'Moderate fraud risk requires manual review';
        } else {
            $reasons[] = 'Low fraud risk - application approved';
        }

        // Add specific reasons from rules engine
        if (!empty($rulesResult['risk_factors'])) {
            foreach ($rulesResult['risk_factors'] as $factor) {
                $reasons[] = $factor['description'] ?? $factor['factor'];
            }
        }

        // Add ML insights
        if ($mlResult['risk_tier'] === 'high') {
            $reasons[] = 'Machine learning model indicates high fraud probability';
        }

        // Add LLM insights
        if (!empty($adjudicatorResult['rationale'])) {
            $reasons = array_merge($reasons, $adjudicatorResult['rationale']);
        }

        return [
            'decision' => $decision,
            'reasons' => array_unique($reasons),
            'confidence' => min(0.95, max(0.6, ($ruleScore + $mlScore + $adjudicatorScore) / 3)),
            'policy_version' => 'decision_v1.0.0',
        ];
    }

    /**
     * Mock rules processing fallback
     */
    private function mockRulesProcessing(FraudRequest $fraudRequest): array
    {
        $applicationData = $fraudRequest->application_data;
        
        $hardFail = false;
        $reasons = [];
        $riskFactors = [];
        $score = 0.0;

        // Basic validation checks
        if (!$this->isValidSin($applicationData['applicant']['sin'] ?? '')) {
            $hardFail = true;
            $reasons[] = 'Invalid SIN format detected';
        }

        $age = $this->calculateAge($applicationData['applicant']['date_of_birth'] ?? null);
        if ($age && $age < 18) {
            $hardFail = true;
            $reasons[] = 'Applicant is under 18 years old';
        }

        return [
            'hard_fail' => $hardFail,
            'hard_fail_reasons' => $reasons,
            'risk_score' => $score,
            'risk_factors' => $riskFactors,
            'rules_applied' => ['MockValidationRules'],
            'processing_time_ms' => 10,
            'version' => 'rules_v1.0.0-mock',
        ];
    }

    /**
     * Mock feature extraction fallback
     */
    private function mockFeatureExtraction(FraudRequest $fraudRequest): array
    {
        $redactedData = $fraudRequest->getRedactedData();
        
        $features = [
            'credit_score' => $redactedData['credit_score'] ?? 650,
            'debt_to_income_ratio' => $this->calculateDebtToIncome($redactedData),
            'loan_to_value_ratio' => $this->calculateLtvRatio($redactedData),
            'employment_months' => $redactedData['employment_months'] ?? 24,
            'annual_income' => $redactedData['annual_income'] ?? 50000,
            'vehicle_age' => date('Y') - ($redactedData['vehicle_year'] ?? date('Y')),
            'credit_history_years' => $redactedData['credit_history_years'] ?? 5,
            'delinquencies_24m' => $redactedData['delinquencies_24m'] ?? 0,
            'loan_amount' => $redactedData['loan_amount'] ?? 25000,
            'vehicle_value' => $redactedData['vehicle_value'] ?? 30000,
            'credit_utilization' => $redactedData['credit_utilization'] ?? 30,
            'recent_inquiries_6m' => $redactedData['recent_inquiries_6m'] ?? 1,
            'address_months' => $redactedData['address_months'] ?? 24,
            'loan_term_months' => $redactedData['loan_term_months'] ?? 60,
            'applicant_age' => $redactedData['age'] ?? 35,
        ];

        return [
            'features' => $features,
            'feature_vector' => array_values($features),
            'feature_names' => array_keys($features),
            'processing_time_ms' => 15,
            'version' => 'features_v1.0.0-mock',
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
        return $vehicleValue > 0 ? ($loanAmount / $vehicleValue) * 100 : 85.0;
    }

    private function calculateDebtToIncome(array $data): float
    {
        $loanAmount = $data['loan_amount'] ?? 0;
        $income = $data['annual_income'] ?? 1;
        $termMonths = $data['loan_term_months'] ?? 60;
        
        if ($income > 0 && $termMonths > 0) {
            $monthlyPayment = $loanAmount / $termMonths;
            $monthlyIncome = $income / 12;
            return ($monthlyPayment / $monthlyIncome) * 100;
        }
        
        return 35.0;
    }
}

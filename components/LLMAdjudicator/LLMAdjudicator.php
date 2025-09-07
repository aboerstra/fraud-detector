<?php

namespace Components\LLMAdjudicator;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Exception;

/**
 * LLM Adjudicator Service
 * 
 * Provides intelligent fraud adjudication using Large Language Models
 * via configurable providers (OpenRouter, OpenAI, etc.)
 */
class LLMAdjudicator
{
    private array $config;
    private string $provider;
    private string $apiKey;
    private string $endpoint;
    private string $model;

    public function __construct()
    {
        $this->config = config('services.llm_adjudicator');
        $this->provider = $this->config['provider'];
        $this->apiKey = $this->config['api_key'];
        $this->endpoint = $this->config['endpoint'];
        $this->model = $this->config['model'];

        if (empty($this->apiKey)) {
            throw new Exception('LLM Adjudicator API key not configured');
        }
    }

    /**
     * Perform fraud adjudication analysis
     */
    public function adjudicate(array $context): array
    {
        $startTime = microtime(true);

        try {
            // Build the analysis prompt
            $prompt = $this->buildFraudAnalysisPrompt($context);

            // Make API request
            $response = $this->makeApiRequest($prompt);

            // Parse and validate response
            $analysis = $this->parseResponse($response);

            $processingTime = (microtime(true) - $startTime) * 1000;

            Log::info('LLM adjudication completed', [
                'request_id' => $context['request_id'] ?? 'unknown',
                'model' => $this->model,
                'processing_time_ms' => $processingTime,
                'fraud_probability' => $analysis['fraud_probability'] ?? null,
                'confidence' => $analysis['confidence'] ?? null
            ]);

            return [
                'success' => true,
                'analysis' => $analysis,
                'processing_time_ms' => $processingTime,
                'model_used' => $this->model,
                'provider' => $this->provider
            ];

        } catch (Exception $e) {
            $processingTime = (microtime(true) - $startTime) * 1000;

            Log::error('LLM adjudication failed', [
                'request_id' => $context['request_id'] ?? 'unknown',
                'error' => $e->getMessage(),
                'processing_time_ms' => $processingTime
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage(),
                'processing_time_ms' => $processingTime,
                'fallback_used' => true
            ];
        }
    }

    /**
     * Check if adjudication should be triggered based on ML results
     */
    public function shouldTriggerAdjudication(?array $mlResults): bool
    {
        if (!$this->config['enabled']) {
            return false;
        }

        // If ML service failed, trigger LLM adjudication
        if (empty($mlResults) || !isset($mlResults['fraud_probability'])) {
            return true;
        }

        $fraudProb = $mlResults['fraud_probability'];
        $confidence = $mlResults['confidence_score'] ?? 1.0;

        $minThreshold = $this->config['trigger_threshold_min'];
        $maxThreshold = $this->config['trigger_threshold_max'];

        // Trigger for borderline cases or low confidence
        return ($fraudProb >= $minThreshold && $fraudProb <= $maxThreshold) || $confidence < 0.8;
    }

    /**
     * Build comprehensive fraud analysis prompt
     */
    private function buildFraudAnalysisPrompt(array $context): string
    {
        $applicationData = $context['application_data'] ?? [];
        $rulesResults = $context['rules_results'] ?? [];
        $mlResults = $context['ml_results'] ?? [];
        $features = $context['features'] ?? [];

        $prompt = "You are an expert fraud detection analyst for a Canadian auto loan company. Analyze this loan application for potential fraud.\n\n";

        // Application Details
        $prompt .= "=== APPLICATION DETAILS ===\n";
        if (isset($applicationData['applicant'])) {
            $applicant = $applicationData['applicant'];
            $prompt .= "Applicant: {$applicant['first_name']} {$applicant['last_name']}\n";
            $prompt .= "Age: " . (isset($applicant['date_of_birth']) ? $this->calculateAge($applicant['date_of_birth']) : 'Unknown') . "\n";
            $prompt .= "Annual Income: $" . number_format($applicant['annual_income'] ?? 0) . "\n";
            $prompt .= "Employment: {$applicant['employment_months']} months, {$applicant['employment_type']}\n";
            $prompt .= "Credit Score: {$applicant['credit_score']}\n";
            $prompt .= "Location: {$applicant['address']['city']}, {$applicant['address']['province']}\n";
        }

        if (isset($applicationData['loan'])) {
            $loan = $applicationData['loan'];
            $prompt .= "Loan Amount: $" . number_format($loan['amount']) . "\n";
            $prompt .= "Term: {$loan['term_months']} months\n";
            $prompt .= "Interest Rate: {$loan['interest_rate']}%\n";
        }

        if (isset($applicationData['vehicle'])) {
            $vehicle = $applicationData['vehicle'];
            $prompt .= "Vehicle: {$vehicle['year']} {$vehicle['make']} {$vehicle['model']}\n";
            $prompt .= "Value: $" . number_format($vehicle['estimated_value']) . "\n";
            $prompt .= "Mileage: " . number_format($vehicle['mileage']) . "\n";
        }

        // Rules Engine Results
        $prompt .= "\n=== RULES ENGINE ANALYSIS ===\n";
        if (!empty($rulesResults['violations'])) {
            $prompt .= "Rule Violations:\n";
            foreach ($rulesResults['violations'] as $violation) {
                $prompt .= "- {$violation['rule']}: {$violation['reason']} (Severity: {$violation['severity']})\n";
            }
        } else {
            $prompt .= "No rule violations detected.\n";
        }

        // ML Model Results
        $prompt .= "\n=== ML MODEL ANALYSIS ===\n";
        if (!empty($mlResults)) {
            $prompt .= "Fraud Probability: " . number_format(($mlResults['fraud_probability'] ?? 0) * 100, 1) . "%\n";
            $prompt .= "Model Confidence: " . number_format(($mlResults['confidence_score'] ?? 0) * 100, 1) . "%\n";
            $prompt .= "Risk Tier: {$mlResults['risk_tier']}\n";
            
            if (!empty($mlResults['feature_importance'])) {
                $prompt .= "Top Risk Factors:\n";
                foreach (array_slice($mlResults['feature_importance'], 0, 5) as $feature) {
                    $prompt .= "- {$feature['feature_name']}: " . number_format($feature['importance'] * 100, 1) . "%\n";
                }
            }
        } else {
            $prompt .= "ML analysis unavailable or failed.\n";
        }

        // Analysis Instructions
        $prompt .= "\n=== ANALYSIS REQUIRED ===\n";
        $prompt .= "Provide a comprehensive fraud risk assessment considering:\n";
        $prompt .= "1. Income vs loan amount ratio and affordability\n";
        $prompt .= "2. Employment stability and verification concerns\n";
        $prompt .= "3. Credit profile consistency and red flags\n";
        $prompt .= "4. Vehicle value vs loan amount (LTV ratio)\n";
        $prompt .= "5. Geographic and demographic risk factors\n";
        $prompt .= "6. Application data consistency and completeness\n";
        $prompt .= "7. Any unusual patterns or anomalies\n\n";

        $prompt .= "Respond with a JSON object containing:\n";
        $prompt .= "{\n";
        $prompt .= '  "fraud_probability": 0.0-1.0,  // Overall fraud risk score' . "\n";
        $prompt .= '  "confidence": 0.0-1.0,         // Confidence in assessment' . "\n";
        $prompt .= '  "risk_tier": "low|medium|high", // Risk classification' . "\n";
        $prompt .= '  "recommendation": "approve|review|decline", // Decision recommendation' . "\n";
        $prompt .= '  "primary_concerns": ["concern1", "concern2"], // Main risk factors' . "\n";
        $prompt .= '  "reasoning": "Detailed explanation of the assessment", // Analysis rationale' . "\n";
        $prompt .= '  "red_flags": ["flag1", "flag2"], // Specific fraud indicators' . "\n";
        $prompt .= '  "mitigating_factors": ["factor1", "factor2"] // Positive aspects' . "\n";
        $prompt .= "}\n\n";

        $prompt .= "Focus on Canadian lending regulations and typical fraud patterns in auto loans.";

        return $prompt;
    }

    /**
     * Make API request to LLM provider
     */
    private function makeApiRequest(string $prompt): array
    {
        $retryAttempts = $this->config['retry_attempts'];
        $retryDelay = $this->config['retry_delay'];

        for ($attempt = 1; $attempt <= $retryAttempts; $attempt++) {
            try {
                $response = Http::timeout($this->config['timeout'])
                    ->withHeaders([
                        'Authorization' => 'Bearer ' . $this->apiKey,
                        'Content-Type' => 'application/json',
                        'HTTP-Referer' => config('app.url'),
                        'X-Title' => 'Fraud Detection System'
                    ])
                    ->post($this->endpoint, [
                        'model' => $this->model,
                        'messages' => [
                            [
                                'role' => 'user',
                                'content' => $prompt
                            ]
                        ],
                        'max_tokens' => $this->config['max_tokens'],
                        'temperature' => $this->config['temperature'],
                        'response_format' => ['type' => 'json_object']
                    ]);

                if ($response->successful()) {
                    return $response->json();
                }

                throw new Exception("API request failed: " . $response->status() . " - " . $response->body());

            } catch (Exception $e) {
                Log::warning("LLM API attempt {$attempt} failed", [
                    'error' => $e->getMessage(),
                    'attempt' => $attempt,
                    'max_attempts' => $retryAttempts
                ]);

                if ($attempt === $retryAttempts) {
                    throw $e;
                }

                // Wait before retry
                usleep($retryDelay * 1000);
            }
        }

        throw new Exception('All retry attempts failed');
    }

    /**
     * Parse and validate LLM response
     */
    private function parseResponse(array $response): array
    {
        if (!isset($response['choices'][0]['message']['content'])) {
            throw new Exception('Invalid response format from LLM API');
        }

        $content = $response['choices'][0]['message']['content'];
        
        // Clean up the content to extract JSON
        $content = $this->extractJsonFromContent($content);
        
        $analysis = json_decode($content, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            Log::error('JSON parsing failed', [
                'content' => $content,
                'json_error' => json_last_error_msg()
            ]);
            throw new Exception('Failed to parse JSON response: ' . json_last_error_msg());
        }

        // Validate required fields
        $requiredFields = ['fraud_probability', 'confidence', 'risk_tier', 'recommendation', 'reasoning'];
        foreach ($requiredFields as $field) {
            if (!isset($analysis[$field])) {
                throw new Exception("Missing required field: {$field}");
            }
        }

        // Validate ranges
        if ($analysis['fraud_probability'] < 0 || $analysis['fraud_probability'] > 1) {
            throw new Exception('Invalid fraud_probability value');
        }

        if ($analysis['confidence'] < 0 || $analysis['confidence'] > 1) {
            throw new Exception('Invalid confidence value');
        }

        // Validate enums
        if (!in_array($analysis['risk_tier'], ['low', 'medium', 'high'])) {
            throw new Exception('Invalid risk_tier value');
        }

        if (!in_array($analysis['recommendation'], ['approve', 'review', 'decline'])) {
            throw new Exception('Invalid recommendation value');
        }

        return $analysis;
    }

    /**
     * Extract JSON from LLM response content
     */
    private function extractJsonFromContent(string $content): string
    {
        // Remove markdown code blocks if present
        $content = preg_replace('/```json\s*/', '', $content);
        $content = preg_replace('/```\s*$/', '', $content);
        
        // Try to find JSON object boundaries
        $start = strpos($content, '{');
        $end = strrpos($content, '}');
        
        if ($start !== false && $end !== false && $end > $start) {
            $content = substr($content, $start, $end - $start + 1);
        }
        
        // Clean up any extra whitespace
        $content = trim($content);
        
        return $content;
    }

    /**
     * Calculate age from date of birth
     */
    private function calculateAge(string $dateOfBirth): int
    {
        try {
            $dob = new \DateTime($dateOfBirth);
            $now = new \DateTime();
            return $now->diff($dob)->y;
        } catch (Exception $e) {
            return 0;
        }
    }

    /**
     * Get service health status
     */
    public function getHealthStatus(): array
    {
        try {
            $testResponse = Http::timeout(5)
                ->withHeaders([
                    'Authorization' => 'Bearer ' . $this->apiKey,
                    'Content-Type' => 'application/json'
                ])
                ->post($this->endpoint, [
                    'model' => $this->model,
                    'messages' => [
                        ['role' => 'user', 'content' => 'Test connection. Respond with: {"status": "ok"}']
                    ],
                    'max_tokens' => 50
                ]);

            return [
                'status' => $testResponse->successful() ? 'healthy' : 'unhealthy',
                'provider' => $this->provider,
                'model' => $this->model,
                'endpoint' => $this->endpoint,
                'response_time_ms' => $testResponse->transferStats?->getTransferTime() * 1000 ?? null,
                'enabled' => $this->config['enabled']
            ];

        } catch (Exception $e) {
            return [
                'status' => 'unhealthy',
                'provider' => $this->provider,
                'model' => $this->model,
                'endpoint' => $this->endpoint,
                'error' => $e->getMessage(),
                'enabled' => $this->config['enabled']
            ];
        }
    }
}

<?php

namespace Components\LLMAdjudicator;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Exception;
use Illuminate\Support\Facades\Validator;

/**
 * Outcome enumeration for LLM adjudication decisions
 */
enum Outcome: string
{
    case APPROVE = 'approve';
    case CONDITIONAL = 'conditional'; // auto-stips
    case DECLINE = 'decline';
    case REVIEW = 'review'; // human queue
}

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
    
    // Circuit breaker state
    private static array $circuitBreakerState = [];
    private const CIRCUIT_BREAKER_THRESHOLD = 5; // failures before opening
    private const CIRCUIT_BREAKER_TIMEOUT = 300; // 5 minutes in seconds
    
    // PII patterns for redaction
    private array $piiPatterns = [
        'sin' => '/\b\d{3}[-\s]?\d{3}[-\s]?\d{3}\b/',
        'phone' => '/\b\d{3}[-.\s]?\d{3}[-.\s]?\d{4}\b/',
        'postal_code' => '/\b[A-Z]\d[A-Z][-\s]?\d[A-Z]\d\b/i',
        'email' => '/\b[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Z|a-z]{2,}\b/',
        'credit_card' => '/\b\d{4}[-\s]?\d{4}[-\s]?\d{4}[-\s]?\d{4}\b/'
    ];
    
    // JSON schema for response validation (Laravel format)
    private array $responseSchema = [
        'fraud_probability' => 'required|numeric|between:0,1',
        'confidence' => 'required|numeric|between:0,1',
        'risk_tier' => 'required|in:low,medium,high',
        'recommendation' => 'required|in:approve,conditional,decline,review',
        'reasoning' => 'required|string|min:10|max:3000',
        'primary_concerns' => 'array',
        'red_flags' => 'array',
        'mitigating_factors' => 'array',
        'signals' => 'required|array',
        'signals.fraud_hard_fail' => 'required|boolean',
        'signals.consortium_hit' => 'required|boolean',
        'signals.doc_verification' => 'required|in:pass,fail,not_performed',
        'signals.synthetic_id' => 'required|boolean',
        'signals.velocity' => 'required|in:none,low,medium,high',
        'credit' => 'required|array',
        'credit.score' => 'required|integer|between:300,900',
        'credit.pti' => 'required|numeric|between:0,1',
        'credit.tds' => 'required|numeric|between:0,1',
        'credit.ltv' => 'required|numeric|between:0,3',
        'credit.structure_ok' => 'required|boolean',
        'stipulations' => 'array'
    ];

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
     * Get strict JSON schema for LLM response format
     */
    private function responseJsonSchema(): array
    {
        return [
            'type' => 'json_schema',
            'json_schema' => [
                'name' => 'FraudAdjudication',
                'schema' => [
                    'type' => 'object',
                    'additionalProperties' => false,
                    'required' => ['fraud_probability','confidence','risk_tier','recommendation','reasoning','signals','credit'],
                    'properties' => [
                        'fraud_probability' => ['type' => 'number','minimum' => 0,'maximum' => 1],
                        'confidence' => ['type' => 'number','minimum' => 0,'maximum' => 1],
                        'risk_tier' => ['type' => 'string','enum' => ['low','medium','high']],
                        'recommendation' => ['type' => 'string','enum' => ['approve','conditional','decline','review']],
                        'reasoning' => ['type' => 'string','maxLength' => 3000],
                        'primary_concerns' => ['type' => 'array','items' => ['type' => 'string'], 'maxItems' => 10, 'default' => []],
                        'red_flags' => ['type' => 'array','items' => ['type' => 'string'], 'maxItems' => 20, 'default' => []],
                        'mitigating_factors' => ['type' => 'array','items' => ['type' => 'string'], 'maxItems' => 10, 'default' => []],
                        'signals' => [
                            'type' => 'object',
                            'additionalProperties' => false,
                            'required' => ['fraud_hard_fail','consortium_hit','doc_verification','synthetic_id','velocity'],
                            'properties' => [
                                'fraud_hard_fail' => ['type' => 'boolean'],
                                'consortium_hit' => ['type' => 'boolean'],
                                'doc_verification' => ['type' => 'string','enum' => ['pass','fail','not_performed']],
                                'synthetic_id' => ['type' => 'boolean'],
                                'velocity' => ['type' => 'string','enum' => ['none','low','medium','high']],
                                'reason_codes' => ['type' => 'array','items' => ['type' => 'string'], 'default' => []]
                            ]
                        ],
                        'credit' => [
                            'type' => 'object',
                            'additionalProperties' => false,
                            'required' => ['score','pti','tds','ltv','structure_ok','marginal_reason'],
                            'properties' => [
                                'score' => ['type' => 'integer','minimum' => 300,'maximum' => 900],
                                'pti' => ['type' => 'number','minimum' => 0,'maximum' => 1],
                                'tds' => ['type' => 'number','minimum' => 0,'maximum' => 1],
                                'ltv' => ['type' => 'number','minimum' => 0,'maximum' => 3],
                                'structure_ok' => ['type' => 'boolean'],
                                'marginal_reason' => ['type' => 'string','default' => '']
                            ]
                        ],
                        'stipulations' => [
                            'type' => 'array',
                            'items' => [
                                'type' => 'object',
                                'additionalProperties' => false,
                                'required' => ['type','detail'],
                                'properties' => [
                                    'type' => ['type' => 'string','enum' => ['increase_down_payment','reduce_term','add_co_borrower','provide_income_docs','address_proof','employer_verification']],
                                    'detail' => ['type' => 'string','maxLength' => 500]
                                ]
                            ],
                            'default' => []
                        ]
                    ]
                ],
                'strict' => true
            ]
        ];
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

            // Make final decision with auto-stipulations
            $decision = $this->decide($analysis);

            $processingTime = (microtime(true) - $startTime) * 1000;

            Log::info('LLM adjudication completed', [
                'request_id' => $context['request_id'] ?? 'unknown',
                'model' => $this->model,
                'processing_time_ms' => $processingTime,
                'fraud_probability' => $analysis['fraud_probability'] ?? null,
                'confidence' => $analysis['confidence'] ?? null,
                'risk_tier' => $analysis['risk_tier'] ?? null,
                'recommendation' => $analysis['recommendation'] ?? null,
                'final_outcome' => $decision['outcome'] ?? null,
                'queue_required' => $decision['queue'] ?? false
            ]);

            return [
                'success' => true,
                'analysis' => $analysis,
                'decision' => $decision,
                'processing_time_ms' => $processingTime,
                'model_used' => $this->model,
                'provider' => $this->provider
            ];

        } catch (Exception $e) {
            $processingTime = (microtime(true) - $startTime) * 1000;

            Log::error('LLM adjudication failed', [
                'request_id' => $context['request_id'] ?? 'unknown',
                'error' => $this->redactPII($e->getMessage()),
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
     * Make final decision with auto-stipulations and routing logic
     */
    private function decide(array $analysis): array
    {
        $cfg = $this->config;
        $fraud = $analysis['fraud_probability'];
        $conf = $analysis['confidence'];
        $rec = $analysis['recommendation'];
        $sig = $analysis['signals'] ?? [];
        $cred = $analysis['credit'] ?? [];

        // Hard overrides
        if (!empty($sig['fraud_hard_fail'])) {
            return [
                'outcome' => Outcome::DECLINE->value,
                'queue' => false,
                'reason' => 'Hard fraud signal',
                'stipulations' => []
            ];
        }

        // Low-confidence safety valve
        if ($conf < ($cfg['min_confidence_for_auto'] ?? 0.75)) {
            return [
                'outcome' => Outcome::REVIEW->value,
                'queue' => true,
                'reason' => 'Low confidence',
                'stipulations' => []
            ];
        }

        // Straight-through fraud pass gate
        if ($fraud > ($cfg['fraud_decline_threshold'] ?? 0.8)) {
            return [
                'outcome' => Outcome::DECLINE->value,
                'queue' => false,
                'reason' => 'High fraud probability',
                'stipulations' => []
            ];
        }
        
        if ($fraud > ($cfg['fraud_review_threshold'] ?? 0.35)) {
            return [
                'outcome' => Outcome::REVIEW->value,
                'queue' => true,
                'reason' => 'Fraud gray zone',
                'stipulations' => []
            ];
        }

        // Credit policy gates
        $ptiCap = $cfg['pti_cap'] ?? 0.15;
        $tdsCap = $cfg['tds_cap'] ?? 0.45;
        $ltvCap = $cfg['ltv_cap'] ?? 1.20;

        $ptiOk = ($cred['pti'] ?? 1) <= $ptiCap;
        $tdsOk = ($cred['tds'] ?? 1) <= $tdsCap;
        $ltvOk = ($cred['ltv'] ?? 2) <= $ltvCap;
        $structureOk = (bool)($cred['structure_ok'] ?? false);

        if ($ptiOk && $tdsOk && $ltvOk && $structureOk) {
            return [
                'outcome' => Outcome::APPROVE->value,
                'queue' => false,
                'reason' => 'Meets policy',
                'stipulations' => []
            ];
        }

        // Conditional: compute parametric stips if not provided
        $stips = $analysis['stipulations'] ?? [];
        
        if (!$ptiOk) {
            $stips[] = [
                'type' => 'reduce_term',
                'detail' => 'Lower PTI by reducing term by 12 months'
            ];
            $stips[] = [
                'type' => 'increase_down_payment',
                'detail' => 'Increase down payment until PTI <= ' . ($ptiCap * 100) . '%'
            ];
        }
        
        if (!$ltvOk) {
            $stips[] = [
                'type' => 'increase_down_payment',
                'detail' => 'Decrease LTV to <= ' . ($ltvCap * 100) . '%'
            ];
        }
        
        if (!$tdsOk) {
            $stips[] = [
                'type' => 'add_co_borrower',
                'detail' => 'Add qualified co-borrower to reduce TDS'
            ];
        }

        // If stips exist and are mechanical, we can auto-conditional
        if (!empty($stips)) {
            return [
                'outcome' => Outcome::CONDITIONAL->value,
                'queue' => false,
                'reason' => 'Auto-stip',
                'stipulations' => $stips
            ];
        }

        // Otherwise, send to human
        return [
            'outcome' => Outcome::REVIEW->value,
            'queue' => true,
            'reason' => 'Unclear credit structure',
            'stipulations' => []
        ];
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
     * Build comprehensive fraud analysis prompt with strict schema requirements
     */
    private function buildFraudAnalysisPrompt(array $context): string
    {
        $applicationData = $context['application_data'] ?? [];
        $rulesResults = $context['rules_results'] ?? [];
        $mlResults = $context['ml_results'] ?? [];
        $features = $context['features'] ?? [];

        $prompt = <<<PROMPT
You are a senior Canadian auto-loan fraud analyst. Your job is to output ONLY a strict JSON object matching the provided schema.

Rules:
- Do NOT invent data. If a field is unknown or not provided, reason about its impact and reduce "confidence".
- If fraud indicators are conflicting or document verification is missing, recommend "review".
- If hard fraud conditions are present (e.g., confirmed tampered ID, confirmed consortium fraud, synthetic identity), set signals.fraud_hard_fail=true and recommend "decline".
- If fraud risk is low and credit policy is clearly met, recommend "approve".
- If approval is possible with concrete, mechanical changes (e.g., down payment, shorter term), use "conditional" and populate "stipulations".
- Keep "reasoning" concise and factual (< 3000 chars).

Return ONLY the JSON. No prose.

=== INPUT CONTEXT ===
{$this->renderContext($applicationData, $rulesResults, $mlResults, $features)}
PROMPT;

        return $prompt;
    }

    /**
     * Render context data for the LLM prompt
     */
    private function renderContext(array $applicationData, array $rulesResults, array $mlResults, array $features): string
    {
        $context = "";

        // Application Details
        $context .= "=== APPLICATION DETAILS ===\n";
        if (isset($applicationData['applicant'])) {
            $applicant = $applicationData['applicant'];
            $context .= "Applicant: {$applicant['first_name']} {$applicant['last_name']}\n";
            $context .= "Age: " . (isset($applicant['date_of_birth']) ? $this->calculateAge($applicant['date_of_birth']) : 'Unknown') . "\n";
            $context .= "Annual Income: $" . number_format($applicant['annual_income'] ?? 0) . "\n";
            $context .= "Employment: {$applicant['employment_months']} months, {$applicant['employment_type']}\n";
            $context .= "Credit Score: {$applicant['credit_score']}\n";
            $context .= "Location: {$applicant['address']['city']}, {$applicant['address']['province']}\n";
        }

        if (isset($applicationData['loan'])) {
            $loan = $applicationData['loan'];
            $context .= "Loan Amount: $" . number_format($loan['amount']) . "\n";
            $context .= "Term: {$loan['term_months']} months\n";
            $context .= "Interest Rate: {$loan['interest_rate']}%\n";
        }

        if (isset($applicationData['vehicle'])) {
            $vehicle = $applicationData['vehicle'];
            $context .= "Vehicle: {$vehicle['year']} {$vehicle['make']} {$vehicle['model']}\n";
            $context .= "Value: $" . number_format($vehicle['estimated_value']) . "\n";
            $context .= "Mileage: " . number_format($vehicle['mileage']) . "\n";
        }

        // Rules Engine Results
        $context .= "\n=== RULES ENGINE ANALYSIS ===\n";
        if (!empty($rulesResults['violations'])) {
            $context .= "Rule Violations:\n";
            foreach ($rulesResults['violations'] as $violation) {
                $context .= "- {$violation['rule']}: {$violation['reason']} (Severity: {$violation['severity']})\n";
            }
        } else {
            $context .= "No rule violations detected.\n";
        }

        // ML Model Results
        $context .= "\n=== ML MODEL ANALYSIS ===\n";
        if (!empty($mlResults)) {
            $context .= "Fraud Probability: " . number_format(($mlResults['fraud_probability'] ?? 0) * 100, 1) . "%\n";
            $context .= "Model Confidence: " . number_format(($mlResults['confidence_score'] ?? 0) * 100, 1) . "%\n";
            $context .= "Risk Tier: {$mlResults['risk_tier']}\n";
            
            if (!empty($mlResults['feature_importance'])) {
                $context .= "Top Risk Factors:\n";
                foreach (array_slice($mlResults['feature_importance'], 0, 5) as $feature) {
                    $context .= "- {$feature['feature_name']}: " . number_format($feature['importance'] * 100, 1) . "%\n";
                }
            }
        } else {
            $context .= "ML analysis unavailable or failed.\n";
        }

        return $context;
    }

    /**
     * Make API request to LLM provider with circuit breaker protection
     */
    private function makeApiRequest(string $prompt): array
    {
        // Check circuit breaker
        if ($this->isCircuitBreakerOpen()) {
            throw new Exception('Circuit breaker is open - LLM service temporarily unavailable');
        }

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
                        'temperature' => $this->config['temperature'] ?? 0.1, // Low for determinism
                        'top_p' => 1.0,
                        'response_format' => $this->responseJsonSchema(),
                        'seed' => 7 // For determinism if supported
                    ]);

                if ($response->successful()) {
                    // Reset circuit breaker on success
                    $this->recordCircuitBreakerSuccess();
                    return $response->json();
                }

                throw new Exception("API request failed: " . $response->status() . " - " . $response->body());

            } catch (Exception $e) {
                // Record failure for circuit breaker
                $this->recordCircuitBreakerFailure();
                
                Log::warning("LLM API attempt {$attempt} failed", [
                    'error' => $this->redactPII($e->getMessage()),
                    'attempt' => $attempt,
                    'max_attempts' => $retryAttempts
                ]);

                if ($attempt === $retryAttempts) {
                    throw $e;
                }

                // Exponential backoff with jitter
                $base = $this->config['retry_delay'] ?? 200; // ms
                $wait = (int)($base * pow(2, $attempt - 1) + random_int(0, 100));
                usleep($wait * 1000);
            }
        }

        throw new Exception('All retry attempts failed');
    }

    /**
     * Check if circuit breaker is open
     */
    private function isCircuitBreakerOpen(): bool
    {
        $key = $this->getCircuitBreakerKey();
        $state = self::$circuitBreakerState[$key] ?? null;

        if (!$state) {
            return false;
        }

        // Check if timeout has passed
        if (time() - $state['opened_at'] > self::CIRCUIT_BREAKER_TIMEOUT) {
            // Reset circuit breaker
            unset(self::$circuitBreakerState[$key]);
            return false;
        }

        return $state['failures'] >= self::CIRCUIT_BREAKER_THRESHOLD;
    }

    /**
     * Record circuit breaker failure
     */
    private function recordCircuitBreakerFailure(): void
    {
        $key = $this->getCircuitBreakerKey();
        
        if (!isset(self::$circuitBreakerState[$key])) {
            self::$circuitBreakerState[$key] = [
                'failures' => 0,
                'opened_at' => time()
            ];
        }

        self::$circuitBreakerState[$key]['failures']++;
        
        if (self::$circuitBreakerState[$key]['failures'] >= self::CIRCUIT_BREAKER_THRESHOLD) {
            self::$circuitBreakerState[$key]['opened_at'] = time();
            Log::warning('Circuit breaker opened for LLM service', [
                'provider' => $this->provider,
                'endpoint' => $this->endpoint,
                'failures' => self::$circuitBreakerState[$key]['failures']
            ]);
        }
    }

    /**
     * Record circuit breaker success
     */
    private function recordCircuitBreakerSuccess(): void
    {
        $key = $this->getCircuitBreakerKey();
        unset(self::$circuitBreakerState[$key]);
    }

    /**
     * Get circuit breaker key for this instance
     */
    private function getCircuitBreakerKey(): string
    {
        return md5($this->provider . ':' . $this->endpoint);
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

        // Use Laravel validator for additional schema validation
        $validator = Validator::make($analysis, $this->responseSchema);
        
        if ($validator->fails()) {
            Log::warning('LLM response validation failed', [
                'errors' => $validator->errors()->toArray(),
                'analysis' => $analysis
            ]);
            // Continue with basic validation for now, but log the issues
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
     * Redact PII from text for safe logging
     */
    private function redactPII(string $text): string
    {
        foreach ($this->piiPatterns as $type => $pattern) {
            $replacement = match($type) {
                'sin' => '[SIN-REDACTED]',
                'phone' => '[PHONE-REDACTED]',
                'postal_code' => '[POSTAL-REDACTED]',
                'email' => '[EMAIL-REDACTED]',
                'credit_card' => '[CC-REDACTED]',
                default => '[PII-REDACTED]'
            };
            $text = preg_replace($pattern, $replacement, $text);
        }
        return $text;
    }

    /**
     * Get enhanced service health status with canary testing
     */
    public function getHealthStatus(): array
    {
        $startTime = microtime(true);
        $canaryResult = null;
        
        try {
            // Basic connectivity test
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

            $responseTime = (microtime(true) - $startTime) * 1000;
            $isHealthy = $testResponse->successful();

            // Perform canary adjudication test if basic test passes
            if ($isHealthy && !$this->isCircuitBreakerOpen()) {
                $canaryResult = $this->performCanaryTest();
            }

            return [
                'status' => $isHealthy ? 'healthy' : 'unhealthy',
                'provider' => $this->provider,
                'model' => $this->model,
                'endpoint' => $this->endpoint,
                'response_time_ms' => round($responseTime, 2),
                'enabled' => $this->config['enabled'],
                'circuit_breaker' => [
                    'status' => $this->isCircuitBreakerOpen() ? 'open' : 'closed',
                    'failures' => $this->getCircuitBreakerFailures()
                ],
                'canary_test' => $canaryResult,
                'config_check' => [
                    'api_key_configured' => !empty($this->apiKey),
                    'endpoint_configured' => !empty($this->endpoint),
                    'model_configured' => !empty($this->model),
                    'retry_attempts' => $this->config['retry_attempts'] ?? 3,
                    'timeout' => $this->config['timeout'] ?? 30
                ],
                'last_check' => now()->toISOString(),
                'http_status' => $testResponse->status()
            ];

        } catch (Exception $e) {
            $responseTime = (microtime(true) - $startTime) * 1000;
            
            return [
                'status' => 'unhealthy',
                'provider' => $this->provider,
                'model' => $this->model,
                'endpoint' => $this->endpoint,
                'error' => $this->redactPII($e->getMessage()),
                'response_time_ms' => round($responseTime, 2),
                'enabled' => $this->config['enabled'],
                'circuit_breaker' => [
                    'status' => $this->isCircuitBreakerOpen() ? 'open' : 'closed',
                    'failures' => $this->getCircuitBreakerFailures()
                ],
                'canary_test' => null,
                'config_check' => [
                    'api_key_configured' => !empty($this->apiKey),
                    'endpoint_configured' => !empty($this->endpoint),
                    'model_configured' => !empty($this->model)
                ],
                'last_check' => now()->toISOString()
            ];
        }
    }

    /**
     * Perform canary adjudication test with fixed sample
     */
    private function performCanaryTest(): array
    {
        $canaryStartTime = microtime(true);
        
        try {
            // Fixed canary sample for consistent testing
            $canaryContext = [
                'request_id' => 'canary-test-' . time(),
                'application_data' => [
                    'applicant' => [
                        'first_name' => 'John',
                        'last_name' => 'Doe',
                        'date_of_birth' => '1985-01-01',
                        'annual_income' => 75000,
                        'employment_months' => 24,
                        'employment_type' => 'full_time',
                        'credit_score' => 720,
                        'address' => [
                            'city' => 'Toronto',
                            'province' => 'ON'
                        ]
                    ],
                    'loan' => [
                        'amount' => 25000,
                        'term_months' => 60,
                        'interest_rate' => 5.5
                    ],
                    'vehicle' => [
                        'year' => 2020,
                        'make' => 'Toyota',
                        'model' => 'Camry',
                        'estimated_value' => 28000,
                        'mileage' => 45000
                    ]
                ],
                'rules_results' => ['violations' => []],
                'ml_results' => [
                    'fraud_probability' => 0.15,
                    'confidence_score' => 0.85,
                    'risk_tier' => 'low'
                ]
            ];

            $prompt = $this->buildFraudAnalysisPrompt($canaryContext);
            $response = $this->makeApiRequest($prompt);
            $analysis = $this->parseResponse($response);
            $decision = $this->decide($analysis);

            $canaryTime = (microtime(true) - $canaryStartTime) * 1000;

            // Validate expected outcome for this canary case
            $expectedOutcome = Outcome::APPROVE->value; // This should be a clear approve case
            $outcomeMatches = ($decision['outcome'] ?? null) === $expectedOutcome;

            return [
                'status' => 'passed',
                'processing_time_ms' => round($canaryTime, 2),
                'outcome_correct' => $outcomeMatches,
                'expected_outcome' => $expectedOutcome,
                'actual_outcome' => $decision['outcome'] ?? null,
                'schema_valid' => isset($analysis['fraud_probability'], $analysis['confidence'], $analysis['signals'], $analysis['credit'])
            ];

        } catch (Exception $e) {
            $canaryTime = (microtime(true) - $canaryStartTime) * 1000;
            
            return [
                'status' => 'failed',
                'processing_time_ms' => round($canaryTime, 2),
                'error' => $this->redactPII($e->getMessage()),
                'outcome_correct' => false,
                'schema_valid' => false
            ];
        }
    }

    /**
     * Get circuit breaker failure count
     */
    private function getCircuitBreakerFailures(): int
    {
        $key = $this->getCircuitBreakerKey();
        return self::$circuitBreakerState[$key]['failures'] ?? 0;
    }

    /**
     * Run migration utility to test all four outcomes
     */
    public function runMigrationTest(): array
    {
        $results = [];
        
        // Test cases for each outcome
        $testCases = [
            'approve' => [
                'description' => 'Clean application that should approve',
                'context' => [
                    'application_data' => [
                        'applicant' => [
                            'annual_income' => 80000,
                            'credit_score' => 750,
                            'employment_months' => 36
                        ],
                        'loan' => ['amount' => 20000],
                        'vehicle' => ['estimated_value' => 25000]
                    ],
                    'rules_results' => ['violations' => []],
                    'ml_results' => ['fraud_probability' => 0.1, 'confidence_score' => 0.9]
                ],
                'expected' => Outcome::APPROVE->value
            ],
            'conditional' => [
                'description' => 'Marginal credit that should get stipulations',
                'context' => [
                    'application_data' => [
                        'applicant' => [
                            'annual_income' => 50000,
                            'credit_score' => 650,
                            'employment_months' => 12
                        ],
                        'loan' => ['amount' => 30000],
                        'vehicle' => ['estimated_value' => 32000]
                    ],
                    'rules_results' => ['violations' => []],
                    'ml_results' => ['fraud_probability' => 0.2, 'confidence_score' => 0.8]
                ],
                'expected' => Outcome::CONDITIONAL->value
            ],
            'decline' => [
                'description' => 'High fraud probability case',
                'context' => [
                    'application_data' => [
                        'applicant' => [
                            'annual_income' => 30000,
                            'credit_score' => 500,
                            'employment_months' => 3
                        ],
                        'loan' => ['amount' => 50000],
                        'vehicle' => ['estimated_value' => 15000]
                    ],
                    'rules_results' => ['violations' => []],
                    'ml_results' => ['fraud_probability' => 0.9, 'confidence_score' => 0.85]
                ],
                'expected' => Outcome::DECLINE->value
            ],
            'review' => [
                'description' => 'Low confidence case requiring human review',
                'context' => [
                    'application_data' => [
                        'applicant' => [
                            'annual_income' => 60000,
                            'credit_score' => 680,
                            'employment_months' => 18
                        ],
                        'loan' => ['amount' => 25000],
                        'vehicle' => ['estimated_value' => 28000]
                    ],
                    'rules_results' => ['violations' => []],
                    'ml_results' => ['fraud_probability' => 0.4, 'confidence_score' => 0.6]
                ],
                'expected' => Outcome::REVIEW->value
            ]
        ];

        foreach ($testCases as $testName => $testCase) {
            try {
                $testCase['context']['request_id'] = "migration-test-{$testName}-" . time();
                $result = $this->adjudicate($testCase['context']);
                
                $actualOutcome = $result['decision']['outcome'] ?? null;
                $outcomeMatches = $actualOutcome === $testCase['expected'];
                
                $results[$testName] = [
                    'description' => $testCase['description'],
                    'expected_outcome' => $testCase['expected'],
                    'actual_outcome' => $actualOutcome,
                    'outcome_correct' => $outcomeMatches,
                    'success' => $result['success'] ?? false,
                    'processing_time_ms' => $result['processing_time_ms'] ?? null
                ];
                
            } catch (Exception $e) {
                $results[$testName] = [
                    'description' => $testCase['description'],
                    'expected_outcome' => $testCase['expected'],
                    'actual_outcome' => null,
                    'outcome_correct' => false,
                    'success' => false,
                    'error' => $this->redactPII($e->getMessage())
                ];
            }
        }

        return [
            'migration_test_results' => $results,
            'overall_success' => !in_array(false, array_column($results, 'outcome_correct')),
            'timestamp' => now()->toISOString()
        ];
    }
}

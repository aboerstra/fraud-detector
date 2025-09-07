<?php

require_once 'vendor/autoload.php';

// Initialize Laravel app
$app = require_once 'bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

echo "=== Testing LLM Adjudicator Integration ===\n\n";

// Test 1: LLM Service Health Check
echo "1. Testing LLM Service Health Check...\n";
echo "=====================================\n";

try {
    $llmAdjudicator = new \Components\LLMAdjudicator\LLMAdjudicator();
    $healthStatus = $llmAdjudicator->getHealthStatus();
    
    if ($healthStatus['status'] === 'healthy') {
        echo "✓ LLM Service is healthy!\n";
        echo "  Provider: " . $healthStatus['provider'] . "\n";
        echo "  Model: " . $healthStatus['model'] . "\n";
        echo "  Endpoint: " . $healthStatus['endpoint'] . "\n";
        echo "  Response Time: " . number_format($healthStatus['response_time_ms'] ?? 0, 2) . "ms\n";
        echo "  Enabled: " . ($healthStatus['enabled'] ? 'Yes' : 'No') . "\n";
    } else {
        echo "✗ LLM Service is unhealthy\n";
        echo "  Error: " . ($healthStatus['error'] ?? 'Unknown error') . "\n";
    }
    
} catch (\Exception $e) {
    echo "✗ LLM Service health check failed: " . $e->getMessage() . "\n";
}

echo "\n2. Testing LLM Adjudication Logic...\n";
echo "====================================\n";

try {
    // Create test context for LLM adjudication
    $testContext = [
        'request_id' => 'test-' . uniqid(),
        'application_data' => [
            'applicant' => [
                'first_name' => 'John',
                'last_name' => 'Doe',
                'date_of_birth' => '1985-06-15',
                'annual_income' => 55000,
                'employment_months' => 18,
                'employment_type' => 'full-time',
                'credit_score' => 680,
                'credit_history_years' => 7,
                'delinquencies_24m' => 1,
                'recent_inquiries_6m' => 2,
                'credit_utilization' => 65,
                'address' => [
                    'city' => 'Toronto',
                    'province' => 'Ontario'
                ]
            ],
            'loan' => [
                'amount' => 28000,
                'term_months' => 72,
                'interest_rate' => 7.5
            ],
            'vehicle' => [
                'year' => 2020,
                'make' => 'Toyota',
                'model' => 'Camry',
                'estimated_value' => 32000,
                'mileage' => 45000
            ]
        ],
        'rules_results' => [
            'violations' => [
                [
                    'rule' => 'Credit Utilization Check',
                    'reason' => 'High credit utilization (65%)',
                    'severity' => 'medium'
                ]
            ],
            'risk_score' => 0.45,
            'risk_factors' => [
                [
                    'rule' => 'Credit Risk',
                    'description' => 'Credit utilization above recommended threshold',
                    'severity' => 'medium'
                ]
            ]
        ],
        'ml_results' => [
            'fraud_probability' => 0.35,
            'confidence_score' => 0.78,
            'risk_tier' => 'medium',
            'feature_importance' => [
                ['feature_name' => 'credit_score', 'importance' => 0.25, 'value' => 680],
                ['feature_name' => 'debt_to_income_ratio', 'importance' => 0.20, 'value' => 8.48],
                ['feature_name' => 'loan_to_value_ratio', 'importance' => 0.15, 'value' => 87.5],
                ['feature_name' => 'employment_months', 'importance' => 0.10, 'value' => 18],
                ['feature_name' => 'annual_income', 'importance' => 0.08, 'value' => 55000]
            ]
        ]
    ];

    echo "Sending adjudication request to Claude Sonnet 4...\n";
    echo "Context: Borderline case with medium risk indicators\n";
    
    $startTime = microtime(true);
    $result = $llmAdjudicator->adjudicate($testContext);
    $processingTime = (microtime(true) - $startTime) * 1000;
    
    if ($result['success']) {
        $analysis = $result['analysis'];
        
        echo "✓ LLM Adjudication successful!\n";
        echo "  Processing Time: " . number_format($processingTime, 2) . "ms\n";
        echo "  Model Used: " . ($result['model_used'] ?? 'unknown') . "\n";
        echo "  Provider: " . ($result['provider'] ?? 'unknown') . "\n\n";
        
        echo "Analysis Results:\n";
        echo "  Fraud Probability: " . number_format($analysis['fraud_probability'] * 100, 1) . "%\n";
        echo "  Confidence: " . number_format($analysis['confidence'] * 100, 1) . "%\n";
        echo "  Risk Tier: " . $analysis['risk_tier'] . "\n";
        echo "  Recommendation: " . $analysis['recommendation'] . "\n";
        
        if (!empty($analysis['primary_concerns'])) {
            echo "  Primary Concerns:\n";
            foreach ($analysis['primary_concerns'] as $concern) {
                echo "    - $concern\n";
            }
        }
        
        if (!empty($analysis['red_flags'])) {
            echo "  Red Flags:\n";
            foreach ($analysis['red_flags'] as $flag) {
                echo "    - $flag\n";
            }
        }
        
        if (!empty($analysis['mitigating_factors'])) {
            echo "  Mitigating Factors:\n";
            foreach ($analysis['mitigating_factors'] as $factor) {
                echo "    - $factor\n";
            }
        }
        
        echo "  Reasoning: " . substr($analysis['reasoning'], 0, 200) . "...\n";
        
    } else {
        echo "✗ LLM Adjudication failed\n";
        echo "  Error: " . ($result['error'] ?? 'Unknown error') . "\n";
        echo "  Processing Time: " . number_format($processingTime, 2) . "ms\n";
    }
    
} catch (\Exception $e) {
    echo "✗ LLM Adjudication test failed: " . $e->getMessage() . "\n";
}

echo "\n3. Testing Trigger Logic...\n";
echo "===========================\n";

try {
    $llmAdjudicator = new \Components\LLMAdjudicator\LLMAdjudicator();
    
    // Test case 1: High confidence ML result (should not trigger)
    $highConfidenceML = [
        'fraud_probability' => 0.15,
        'confidence_score' => 0.95,
        'risk_tier' => 'low'
    ];
    
    $shouldTrigger1 = $llmAdjudicator->shouldTriggerAdjudication($highConfidenceML);
    echo "High confidence, low risk: " . ($shouldTrigger1 ? "✓ Triggers LLM" : "✗ No LLM trigger") . "\n";
    
    // Test case 2: Borderline case (should trigger)
    $borderlineML = [
        'fraud_probability' => 0.45,
        'confidence_score' => 0.72,
        'risk_tier' => 'medium'
    ];
    
    $shouldTrigger2 = $llmAdjudicator->shouldTriggerAdjudication($borderlineML);
    echo "Borderline case: " . ($shouldTrigger2 ? "✓ Triggers LLM" : "✗ No LLM trigger") . "\n";
    
    // Test case 3: Low confidence (should trigger)
    $lowConfidenceML = [
        'fraud_probability' => 0.25,
        'confidence_score' => 0.65,
        'risk_tier' => 'low'
    ];
    
    $shouldTrigger3 = $llmAdjudicator->shouldTriggerAdjudication($lowConfidenceML);
    echo "Low confidence: " . ($shouldTrigger3 ? "✓ Triggers LLM" : "✗ No LLM trigger") . "\n";
    
    // Test case 4: ML service failed (should trigger)
    $shouldTrigger4 = $llmAdjudicator->shouldTriggerAdjudication(null);
    echo "ML service failed: " . ($shouldTrigger4 ? "✓ Triggers LLM" : "✗ No LLM trigger") . "\n";
    
} catch (\Exception $e) {
    echo "✗ Trigger logic test failed: " . $e->getMessage() . "\n";
}

echo "\n4. Testing End-to-End Pipeline with LLM...\n";
echo "==========================================\n";

try {
    // Create a test fraud request that will trigger LLM adjudication
    $testData = [
        'applicant' => [
            'first_name' => 'Jane',
            'last_name' => 'Smith',
            'sin' => '987654321',
            'date_of_birth' => '1990-03-20',
            'email' => 'jane.smith@example.com',
            'phone' => '4169876543',
            'annual_income' => 45000,
            'employment_months' => 8, // Short employment - borderline
            'employment_type' => 'contract',
            'industry' => 'consulting',
            'credit_score' => 640, // Borderline credit score
            'credit_history_years' => 3,
            'delinquencies_24m' => 2, // Some delinquencies
            'recent_inquiries_6m' => 4, // Multiple inquiries
            'credit_utilization' => 85, // High utilization
            'address' => [
                'street' => '456 Queen St',
                'city' => 'Toronto',
                'province' => 'Ontario',
                'postal_code' => 'M5V 2B7',
            ],
            'address_months' => 6, // Recent move
        ],
        'loan' => [
            'amount' => 35000, // High loan amount relative to income
            'term_months' => 84,
            'interest_rate' => 9.5,
            'purpose' => 'purchase',
        ],
        'vehicle' => [
            'year' => 2018,
            'make' => 'BMW',
            'model' => 'X3',
            'vin' => '1HGBH41JXMN109187',
            'estimated_value' => 38000,
            'condition' => 'good',
            'mileage' => 65000,
        ],
        'metadata' => [
            'ip_address' => '192.168.1.101',
            'user_agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
            'session_duration' => 180, // Short session
            'referral_source' => 'direct',
        ],
    ];

    // Create fraud request
    $fraudRequest = \App\Models\FraudRequest::create([
        'application_data' => $testData,
        'client_ip' => '192.168.1.101',
        'user_agent' => 'Test Script - LLM Integration',
        'status' => 'queued',
        'submitted_at' => now(),
    ]);

    echo "Created test fraud request: " . $fraudRequest->id . "\n";
    echo "Profile: Borderline case designed to trigger LLM adjudication\n";

    // Execute the fraud detection pipeline
    $job = new \App\Jobs\FraudDetectionJob($fraudRequest->id);
    
    echo "Executing complete fraud detection pipeline...\n";
    $startTime = microtime(true);
    
    $job->handle();
    
    $processingTime = (microtime(true) - $startTime) * 1000;
    
    // Check results
    $fraudRequest->refresh();
    
    echo "✓ Pipeline execution completed!\n";
    echo "  Total Processing Time: " . number_format($processingTime, 2) . "ms\n";
    echo "  Final Status: " . $fraudRequest->status . "\n";
    echo "  Final Decision: " . ($fraudRequest->final_decision ?? 'pending') . "\n";
    echo "  Rule Score: " . number_format(($fraudRequest->rule_score ?? 0) * 100, 1) . "%\n";
    echo "  ML Confidence: " . number_format(($fraudRequest->confidence_score ?? 0) * 100, 1) . "%\n";
    echo "  LLM Score: " . number_format(($fraudRequest->adjudicator_score ?? 0) * 100, 1) . "%\n";
    
    if ($fraudRequest->decision_reasons) {
        echo "  Decision Reasons:\n";
        foreach (array_slice($fraudRequest->decision_reasons, 0, 5) as $reason) {
            echo "    - $reason\n";
        }
        if (count($fraudRequest->decision_reasons) > 5) {
            echo "    ... and " . (count($fraudRequest->decision_reasons) - 5) . " more\n";
        }
    }
    
    // Clean up test data
    $fraudRequest->delete();
    echo "  Test data cleaned up\n";
    
} catch (\Exception $e) {
    echo "✗ End-to-end pipeline test failed: " . $e->getMessage() . "\n";
    echo "  Stack trace: " . $e->getTraceAsString() . "\n";
}

echo "\n=== LLM Adjudicator Testing Complete ===\n";

// Display configuration summary
echo "\nConfiguration Summary:\n";
echo "=====================\n";
echo "LLM Provider: " . config('services.llm_adjudicator.provider') . "\n";
echo "LLM Model: " . config('services.llm_adjudicator.model') . "\n";
echo "LLM Endpoint: " . config('services.llm_adjudicator.endpoint') . "\n";
echo "LLM Enabled: " . (config('services.llm_adjudicator.enabled') ? 'Yes' : 'No') . "\n";
echo "Trigger Thresholds: " . config('services.llm_adjudicator.trigger_threshold_min') . " - " . config('services.llm_adjudicator.trigger_threshold_max') . "\n";
echo "Max Tokens: " . config('services.llm_adjudicator.max_tokens') . "\n";
echo "Temperature: " . config('services.llm_adjudicator.temperature') . "\n";
echo "API Key Configured: " . (config('services.llm_adjudicator.api_key') ? 'Yes' : 'No') . "\n";

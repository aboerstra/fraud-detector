<?php

require_once 'vendor/autoload.php';

// Initialize Laravel app
$app = require_once 'bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

echo "=== Testing ML Service Integration ===\n\n";

// Test 1: Check ML service health
echo "1. Testing ML Service Health Check...\n";
echo "=====================================\n";

try {
    $mlServiceUrl = config('services.ml_service.url', 'http://localhost:8000');
    $timeout = config('services.ml_service.timeout', 30);
    
    echo "ML Service URL: $mlServiceUrl\n";
    
    $response = \Http::timeout($timeout)->get($mlServiceUrl . '/healthz');
    
    if ($response->successful()) {
        $data = $response->json();
        echo "✓ ML Service is healthy!\n";
        echo "  Status: " . ($data['status'] ?? 'unknown') . "\n";
        echo "  Version: " . ($data['version'] ?? 'unknown') . "\n";
        echo "  Models loaded: " . ($data['models_loaded'] ?? 0) . "\n";
        echo "  Model versions: " . implode(', ', $data['model_versions'] ?? []) . "\n";
    } else {
        echo "✗ ML Service health check failed\n";
        echo "  Status code: " . $response->status() . "\n";
        echo "  Response: " . $response->body() . "\n";
    }
    
} catch (\Exception $e) {
    echo "✗ ML Service connection failed: " . $e->getMessage() . "\n";
}

echo "\n2. Testing ML Service Model Info...\n";
echo "===================================\n";

try {
    $response = \Http::timeout($timeout)->get($mlServiceUrl . '/models');
    
    if ($response->successful()) {
        $data = $response->json();
        echo "✓ Model info retrieved successfully!\n";
        echo "  Available models: " . implode(', ', $data['available_models'] ?? []) . "\n";
        echo "  Active model: " . ($data['active_model'] ?? 'unknown') . "\n";
        echo "  Feature count: " . count($data['feature_names'] ?? []) . "\n";
        echo "  Last updated: " . date('Y-m-d H:i:s', $data['last_updated'] ?? 0) . "\n";
    } else {
        echo "✗ Model info request failed\n";
        echo "  Status code: " . $response->status() . "\n";
    }
    
} catch (\Exception $e) {
    echo "✗ Model info request failed: " . $e->getMessage() . "\n";
}

echo "\n3. Testing ML Service Prediction...\n";
echo "===================================\n";

try {
    // Create test feature vector (15 features)
    $testFeatures = [
        680.0,    // credit_score
        8.48,     // debt_to_income_ratio
        87.50,    // loan_to_value_ratio
        18.0,     // employment_months
        55000.0,  // annual_income
        5.0,      // vehicle_age
        7.0,      // credit_history_years
        1.0,      // delinquencies_24m
        28000.0,  // loan_amount
        32000.0,  // vehicle_value
        65.0,     // credit_utilization
        2.0,      // recent_inquiries_6m
        24.0,     // address_months
        72.0,     // loan_term_months
        40.0      // applicant_age
    ];
    
    $payload = [
        'request_id' => 'test-' . uniqid(),
        'feature_vector' => $testFeatures,
        'model_version' => 'latest',
        'include_explanations' => true
    ];
    
    $headers = [
        'Content-Type' => 'application/json',
        'Accept' => 'application/json',
    ];
    
    $apiKey = config('services.ml_service.api_key');
    if ($apiKey) {
        $headers['X-API-Key'] = $apiKey;
    }
    
    echo "Sending prediction request...\n";
    echo "Features: " . count($testFeatures) . " values\n";
    
    $response = \Http::timeout($timeout)
        ->withHeaders($headers)
        ->post($mlServiceUrl . '/predict', $payload);
    
    if ($response->successful()) {
        $data = $response->json();
        echo "✓ Prediction successful!\n";
        echo "  Request ID: " . ($data['request_id'] ?? 'unknown') . "\n";
        echo "  Fraud probability: " . number_format(($data['fraud_probability'] ?? 0) * 100, 2) . "%\n";
        echo "  Confidence score: " . number_format(($data['confidence_score'] ?? 0) * 100, 2) . "%\n";
        echo "  Risk tier: " . ($data['risk_tier'] ?? 'unknown') . "\n";
        echo "  Model version: " . ($data['model_version'] ?? 'unknown') . "\n";
        echo "  Processing time: " . number_format($data['processing_time_ms'] ?? 0, 2) . "ms\n";
        
        if (!empty($data['feature_importance'])) {
            echo "  Top features:\n";
            foreach (array_slice($data['feature_importance'], 0, 5) as $feature) {
                echo "    - " . ($feature['feature_name'] ?? 'unknown') . 
                     ": " . number_format(($feature['importance'] ?? 0) * 100, 1) . "%\n";
            }
        }
    } else {
        echo "✗ Prediction request failed\n";
        echo "  Status code: " . $response->status() . "\n";
        echo "  Response: " . $response->body() . "\n";
    }
    
} catch (\Exception $e) {
    echo "✗ Prediction request failed: " . $e->getMessage() . "\n";
}

echo "\n4. Testing End-to-End Integration...\n";
echo "====================================\n";

try {
    // Create a test fraud request
    $testData = [
        'applicant' => [
            'first_name' => 'John',
            'last_name' => 'Doe',
            'sin' => '123456789',
            'date_of_birth' => '1985-06-15',
            'email' => 'john.doe@example.com',
            'phone' => '4161234567',
            'annual_income' => 55000,
            'employment_months' => 18,
            'employment_type' => 'full-time',
            'industry' => 'technology',
            'credit_score' => 680,
            'credit_history_years' => 7,
            'delinquencies_24m' => 1,
            'recent_inquiries_6m' => 2,
            'credit_utilization' => 65,
            'address' => [
                'street' => '123 Main St',
                'city' => 'Toronto',
                'province' => 'Ontario',
                'postal_code' => 'M5V 3A8',
            ],
            'address_months' => 24,
        ],
        'loan' => [
            'amount' => 28000,
            'term_months' => 72,
            'interest_rate' => 7.5,
            'purpose' => 'purchase',
        ],
        'vehicle' => [
            'year' => 2020,
            'make' => 'Toyota',
            'model' => 'Camry',
            'vin' => '1HGBH41JXMN109186',
            'estimated_value' => 32000,
            'condition' => 'good',
            'mileage' => 45000,
        ],
        'metadata' => [
            'ip_address' => '192.168.1.100',
            'user_agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
            'session_duration' => 420,
            'referral_source' => 'organic search',
        ],
    ];

    // Create fraud request
    $fraudRequest = \App\Models\FraudRequest::create([
        'application_data' => $testData,
        'client_ip' => '192.168.1.100',
        'user_agent' => 'Test Script',
        'status' => 'queued',
        'submitted_at' => now(),
    ]);

    echo "Created test fraud request: " . $fraudRequest->id . "\n";

    // Test the updated FraudDetectionJob
    $job = new \App\Jobs\FraudDetectionJob($fraudRequest->id);
    
    echo "Executing fraud detection pipeline...\n";
    $startTime = microtime(true);
    
    $job->handle();
    
    $processingTime = (microtime(true) - $startTime) * 1000;
    
    // Check results
    $fraudRequest->refresh();
    
    echo "✓ Pipeline execution completed!\n";
    echo "  Processing time: " . number_format($processingTime, 2) . "ms\n";
    echo "  Final status: " . $fraudRequest->status . "\n";
    echo "  Final decision: " . ($fraudRequest->final_decision ?? 'pending') . "\n";
    echo "  Rule score: " . ($fraudRequest->rule_score ?? 'N/A') . "\n";
    echo "  Confidence score: " . ($fraudRequest->confidence_score ?? 'N/A') . "\n";
    echo "  Adjudicator score: " . ($fraudRequest->adjudicator_score ?? 'N/A') . "\n";
    
    if ($fraudRequest->decision_reasons) {
        echo "  Decision reasons:\n";
        foreach ($fraudRequest->decision_reasons as $reason) {
            echo "    - $reason\n";
        }
    }
    
    // Clean up test data
    $fraudRequest->delete();
    echo "  Test data cleaned up\n";
    
} catch (\Exception $e) {
    echo "✗ End-to-end test failed: " . $e->getMessage() . "\n";
    echo "  Stack trace: " . $e->getTraceAsString() . "\n";
}

echo "\n=== ML Service Integration Testing Complete ===\n";

// Display configuration summary
echo "\nConfiguration Summary:\n";
echo "=====================\n";
echo "ML Service URL: " . config('services.ml_service.url') . "\n";
echo "ML Service Enabled: " . (config('services.ml_service.enabled') ? 'Yes' : 'No') . "\n";
echo "ML Service Timeout: " . config('services.ml_service.timeout') . "s\n";
echo "API Key Configured: " . (config('services.ml_service.api_key') ? 'Yes' : 'No') . "\n";

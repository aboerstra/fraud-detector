<?php

require_once 'vendor/autoload.php';

use App\Models\FraudRequest;
use App\Components\RulesEngine\RulesEngine;
use App\Components\FeatureEngineering\FeatureExtractor;

// Initialize Laravel app
$app = require_once 'bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

echo "=== Testing Fraud Detection Components ===\n\n";

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

// Create a mock FraudRequest object
$fraudRequest = new FraudRequest();
$fraudRequest->id = \Illuminate\Support\Str::uuid()->toString();
$fraudRequest->application_data = $testData;

echo "1. Testing Rules Engine...\n";
echo "==========================\n";

try {
    $rulesEngine = new RulesEngine();
    $rulesResult = $rulesEngine->evaluate($fraudRequest);
    
    echo "✓ Rules Engine executed successfully!\n";
    echo "Hard Fail: " . ($rulesResult['hard_fail'] ? 'YES' : 'NO') . "\n";
    echo "Risk Score: " . $rulesResult['risk_score'] . "/100\n";
    echo "Risk Factors: " . count($rulesResult['risk_factors']) . "\n";
    echo "Processing Time: " . $rulesResult['processing_time_ms'] . "ms\n";
    
    if (!empty($rulesResult['risk_factors'])) {
        echo "\nRisk Factors Detected:\n";
        foreach ($rulesResult['risk_factors'] as $factor) {
            echo "  - " . $factor['description'] . " (Score: " . $factor['score'] . ")\n";
        }
    }
    
    echo "\nRules Applied: " . count($rulesResult['rules_applied']) . "\n";
    
} catch (Exception $e) {
    echo "✗ Rules Engine failed: " . $e->getMessage() . "\n";
    echo "Stack trace: " . $e->getTraceAsString() . "\n";
}

echo "\n2. Testing Feature Engineering...\n";
echo "==================================\n";

try {
    $featureExtractor = new FeatureExtractor();
    $featuresResult = $featureExtractor->extractFeatures($fraudRequest);
    
    echo "✓ Feature Engineering executed successfully!\n";
    echo "Features Extracted: " . count($featuresResult['features']) . "\n";
    echo "Processing Time: " . $featuresResult['processing_time_ms'] . "ms\n";
    
    echo "\nTop-15 Features:\n";
    foreach ($featuresResult['feature_names'] as $index => $featureName) {
        $value = $featuresResult['feature_vector'][$index];
        echo sprintf("  %2d. %-25s = %8.2f\n", $index + 1, $featureName, $value);
    }
    
    echo "\nExtractor Results:\n";
    foreach ($featuresResult['extractor_results'] as $result) {
        $status = $result['status'] === 'success' ? '✓' : '✗';
        echo "  $status " . basename($result['extractor']) . " (" . $result['feature_count'] . " features, " . $result['processing_time_ms'] . "ms)\n";
    }
    
} catch (Exception $e) {
    echo "✗ Feature Engineering failed: " . $e->getMessage() . "\n";
    echo "Stack trace: " . $e->getTraceAsString() . "\n";
}

echo "\n3. Testing Integration...\n";
echo "=========================\n";

try {
    // Test that both components work together
    $rulesEngine = new RulesEngine();
    $featureExtractor = new FeatureExtractor();
    
    $rulesResult = $rulesEngine->evaluate($fraudRequest);
    $featuresResult = $featureExtractor->extractFeatures($fraudRequest);
    
    // Simple risk assessment
    $combinedRisk = ($rulesResult['risk_score'] / 100) * 0.4 + 
                   (min($featuresResult['features']['credit_score'], 850) / 850) * 0.6;
    
    echo "✓ Integration test successful!\n";
    echo "Combined Risk Assessment:\n";
    echo "  Rules Risk Score: " . $rulesResult['risk_score'] . "/100\n";
    echo "  Credit Score: " . $featuresResult['features']['credit_score'] . "/850\n";
    echo "  Combined Risk: " . round($combinedRisk * 100, 2) . "%\n";
    
    $recommendation = $combinedRisk > 0.7 ? 'DECLINE' : ($combinedRisk > 0.4 ? 'REVIEW' : 'APPROVE');
    echo "  Recommendation: $recommendation\n";
    
} catch (Exception $e) {
    echo "✗ Integration test failed: " . $e->getMessage() . "\n";
}

echo "\n=== Component Testing Complete ===\n";

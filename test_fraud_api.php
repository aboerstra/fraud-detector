<?php

// Test script for fraud detection API
$baseUrl = 'http://localhost:8080';
$secret = 'your-hmac-secret-key-here'; // This should match HMAC_SECRET_KEY from .env
$apiKey = 'test-api-key'; // API key for authentication

function generateHmacHeaders($method, $path, $body, $secret, $apiKey) {
    $timestamp = time();
    $nonce = bin2hex(random_bytes(16));
    
    // Create the payload to sign (as expected by the controller)
    // Note: path should not include leading slash, as $request->path() doesn't include it
    $pathWithoutSlash = ltrim($path, '/');
    $payload = $method . $pathWithoutSlash . $body . $timestamp . $nonce;
    
    // Generate HMAC signature
    $signature = hash_hmac('sha256', $payload, $secret);
    
    return [
        'X-Api-Key' => $apiKey,
        'X-Timestamp' => $timestamp,
        'X-Nonce' => $nonce,
        'X-Signature' => $signature
    ];
}

// Test data matching the controller validation rules
$testData = [
    'personal_info' => [
        'date_of_birth' => '1990-05-15',
        'sin' => '123456789',
        'province' => 'ON'
    ],
    'contact_info' => [
        'email' => 'test@example.com',
        'phone' => '+1-416-555-0123',
        'address' => [
            'street' => '123 Main Street',
            'city' => 'Toronto',
            'province' => 'ON',
            'postal_code' => 'M5V 3A8'
        ]
    ],
    'financial_info' => [
        'annual_income' => 75000,
        'employment_status' => 'employed'
    ],
    'loan_info' => [
        'amount' => 25000,
        'term_months' => 60,
        'down_payment' => 5000
    ],
    'vehicle_info' => [
        'vin' => '1HGBH41JXMN109186',
        'year' => 2020,
        'make' => 'Honda',
        'model' => 'Civic',
        'mileage' => 45000,
        'value' => 22000
    ],
    'dealer_info' => [
        'dealer_id' => 'DEALER001',
        'location' => 'Toronto, ON'
    ]
];

$body = json_encode($testData);
$path = '/api/applications';
$method = 'POST';

// Generate HMAC headers
$headers = generateHmacHeaders($method, $path, $body, $secret, $apiKey);

// Prepare curl request
$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $baseUrl . $path);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Content-Type: application/json',
    'X-Api-Key: ' . $headers['X-Api-Key'],
    'X-Timestamp: ' . $headers['X-Timestamp'],
    'X-Nonce: ' . $headers['X-Nonce'],
    'X-Signature: ' . $headers['X-Signature']
]);

echo "Testing fraud detection API...\n";
echo "Request URL: " . $baseUrl . $path . "\n";
echo "Request Body: " . $body . "\n";
echo "HMAC Headers: " . json_encode($headers) . "\n\n";

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

echo "Response Code: " . $httpCode . "\n";
echo "Response Body: " . $response . "\n";

if (curl_error($ch)) {
    echo "Curl Error: " . curl_error($ch) . "\n";
}

curl_close($ch);

// If successful, test the decision endpoint
if ($httpCode === 200 || $httpCode === 201) {
    $responseData = json_decode($response, true);
    if (isset($responseData['job_id'])) {
        echo "\n--- Testing Decision Endpoint ---\n";
        $jobId = $responseData['job_id'];
        
        // Wait a moment for processing
        sleep(2);
        
        $decisionUrl = $baseUrl . '/api/decision/' . $jobId;
        echo "Decision URL: " . $decisionUrl . "\n";
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $decisionUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        
        $decisionResponse = curl_exec($ch);
        $decisionHttpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        
        echo "Decision Response Code: " . $decisionHttpCode . "\n";
        echo "Decision Response: " . $decisionResponse . "\n";
        
        curl_close($ch);
    }
}

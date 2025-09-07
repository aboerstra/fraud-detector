# Testing UI Component Plan

## Overview
Web-based testing interface for the fraud detection system that provides an intuitive way to test the API, generate test data using AI, and monitor system health. Built with Laravel Blade templates and Bootstrap 5.

## Local Development Setup

### Prerequisites
- Laravel 12.28.1
- Bootstrap 5
- OpenRouter API key for AI test data generation
- Access to fraud detection API

### Installation
```bash
# Add routes to web.php
Route::get('/test-ui', [TestUIController::class, 'index'])->name('test-ui');
Route::post('/test-ui/generate-data', [TestUIController::class, 'generateTestData']);
Route::post('/test-ui/system-health', [TestUIController::class, 'systemHealth']);

# Start Laravel server
php artisan serve

# Access testing UI
http://localhost:8000/test-ui
```

## Component Responsibilities

### 1. Test Data Generation
- AI-powered test data generation using OpenRouter/Claude
- Risk level selection (low, medium, high, invalid)
- Custom prompt support for specific scenarios
- Canadian-specific data validation (SIN, postal codes, provinces)

### 2. Application Testing
- Comprehensive loan application form
- Real-time form validation
- API submission with progress tracking
- Result polling and display

### 3. System Monitoring
- Real-time health checks for all components
- Performance metrics display
- Error tracking and reporting
- Component status indicators

### 4. Results Analysis
- Visual decision indicators (approve/review/decline)
- Score breakdowns (rules, ML, adjudicator)
- Explainability features display
- Processing time analysis

## User Interface Design

### Main Dashboard Layout
```html
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Fraud Detection Testing UI</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
</head>
<body>
    <div class="container-fluid">
        <div class="row">
            <!-- AI Test Data Generation Panel -->
            <div class="col-md-4">
                <div class="card">
                    <div class="card-header">
                        <h5><i class="bi bi-robot"></i> AI Test Data Generator</h5>
                    </div>
                    <div class="card-body">
                        <!-- Risk level selection -->
                        <!-- Custom prompt input -->
                        <!-- Generate button -->
                    </div>
                </div>
                
                <!-- System Health Panel -->
                <div class="card mt-3">
                    <div class="card-header">
                        <h5><i class="bi bi-heart-pulse"></i> System Health</h5>
                    </div>
                    <div class="card-body">
                        <!-- Component status indicators -->
                        <!-- Performance metrics -->
                    </div>
                </div>
            </div>
            
            <!-- Application Form -->
            <div class="col-md-5">
                <div class="card">
                    <div class="card-header">
                        <h5><i class="bi bi-file-earmark-text"></i> Loan Application</h5>
                    </div>
                    <div class="card-body">
                        <!-- Comprehensive application form -->
                    </div>
                </div>
            </div>
            
            <!-- Results Panel -->
            <div class="col-md-3">
                <div class="card">
                    <div class="card-header">
                        <h5><i class="bi bi-clipboard-data"></i> Results</h5>
                    </div>
                    <div class="card-body">
                        <!-- Decision display -->
                        <!-- Score breakdowns -->
                        <!-- Explainability -->
                    </div>
                </div>
            </div>
        </div>
    </div>
</body>
</html>
```

### AI Test Data Generation Panel
```html
<div class="card">
    <div class="card-header">
        <h5><i class="bi bi-robot"></i> AI Test Data Generator</h5>
    </div>
    <div class="card-body">
        <!-- Risk Level Selection -->
        <div class="mb-3">
            <label class="form-label">Risk Level</label>
            <select class="form-select" id="riskLevel">
                <option value="low">Low Risk</option>
                <option value="medium">Medium Risk</option>
                <option value="high">High Risk</option>
                <option value="invalid">Invalid Data</option>
            </select>
        </div>
        
        <!-- Custom Prompt -->
        <div class="mb-3">
            <label class="form-label">Custom Scenario (Optional)</label>
            <textarea class="form-control" id="customPrompt" rows="3" 
                placeholder="e.g., Young applicant with high income but no credit history"></textarea>
        </div>
        
        <!-- Generate Button -->
        <button class="btn btn-primary w-100" onclick="generateTestData()">
            <i class="bi bi-magic"></i> Generate Test Data
        </button>
        
        <!-- Loading Indicator -->
        <div id="generateLoading" class="text-center mt-2" style="display: none;">
            <div class="spinner-border spinner-border-sm" role="status"></div>
            <span class="ms-2">Generating...</span>
        </div>
        
        <!-- Quick Scenarios -->
        <div class="mt-3">
            <h6>Quick Scenarios</h6>
            <div class="d-grid gap-2">
                <button class="btn btn-outline-success btn-sm" onclick="loadScenario('ideal')">
                    Ideal Applicant
                </button>
                <button class="btn btn-outline-warning btn-sm" onclick="loadScenario('risky')">
                    High Risk Profile
                </button>
                <button class="btn btn-outline-danger btn-sm" onclick="loadScenario('fraudulent')">
                    Fraudulent Application
                </button>
            </div>
        </div>
    </div>
</div>
```

### System Health Monitoring
```html
<div class="card mt-3">
    <div class="card-header d-flex justify-content-between align-items-center">
        <h5><i class="bi bi-heart-pulse"></i> System Health</h5>
        <button class="btn btn-sm btn-outline-primary" onclick="refreshHealth()">
            <i class="bi bi-arrow-clockwise"></i>
        </button>
    </div>
    <div class="card-body">
        <!-- Component Status -->
        <div class="mb-3">
            <div class="d-flex justify-content-between align-items-center mb-2">
                <span>Laravel API</span>
                <span id="laravel-status" class="badge bg-secondary">Checking...</span>
            </div>
            <div class="d-flex justify-content-between align-items-center mb-2">
                <span>ML Service</span>
                <span id="ml-status" class="badge bg-secondary">Checking...</span>
            </div>
            <div class="d-flex justify-content-between align-items-center mb-2">
                <span>LLM Adjudicator</span>
                <span id="llm-status" class="badge bg-secondary">Checking...</span>
            </div>
            <div class="d-flex justify-content-between align-items-center mb-2">
                <span>Database</span>
                <span id="db-status" class="badge bg-secondary">Checking...</span>
            </div>
        </div>
        
        <!-- Performance Metrics -->
        <div class="mt-3">
            <h6>Performance</h6>
            <div class="mb-2">
                <small class="text-muted">Queue Depth</small>
                <div class="progress" style="height: 8px;">
                    <div id="queue-progress" class="progress-bar" style="width: 0%"></div>
                </div>
                <small id="queue-count" class="text-muted">0 jobs</small>
            </div>
            <div class="mb-2">
                <small class="text-muted">Avg Response Time</small>
                <div class="text-end">
                    <span id="avg-response-time" class="badge bg-info">-- ms</span>
                </div>
            </div>
        </div>
    </div>
</div>
```

### Application Form
```html
<form id="applicationForm" class="needs-validation" novalidate>
    <!-- Applicant Information -->
    <div class="card mb-3">
        <div class="card-header">
            <h6>Applicant Information</h6>
        </div>
        <div class="card-body">
            <div class="row">
                <div class="col-md-6 mb-3">
                    <label class="form-label">Age</label>
                    <input type="number" class="form-control" name="age" min="18" max="80" required>
                </div>
                <div class="col-md-6 mb-3">
                    <label class="form-label">SIN</label>
                    <input type="text" class="form-control" name="sin" pattern="[0-9]{9}" required>
                </div>
                <div class="col-md-6 mb-3">
                    <label class="form-label">Province</label>
                    <select class="form-select" name="province" required>
                        <option value="">Select Province</option>
                        <option value="AB">Alberta</option>
                        <option value="BC">British Columbia</option>
                        <option value="MB">Manitoba</option>
                        <option value="NB">New Brunswick</option>
                        <option value="NL">Newfoundland and Labrador</option>
                        <option value="NS">Nova Scotia</option>
                        <option value="ON">Ontario</option>
                        <option value="PE">Prince Edward Island</option>
                        <option value="QC">Quebec</option>
                        <option value="SK">Saskatchewan</option>
                        <option value="NT">Northwest Territories</option>
                        <option value="NU">Nunavut</option>
                        <option value="YT">Yukon</option>
                    </select>
                </div>
                <div class="col-md-6 mb-3">
                    <label class="form-label">Postal Code</label>
                    <input type="text" class="form-control" name="postal_code" 
                           pattern="[A-Za-z][0-9][A-Za-z] [0-9][A-Za-z][0-9]" required>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Loan Information -->
    <div class="card mb-3">
        <div class="card-header">
            <h6>Loan Information</h6>
        </div>
        <div class="card-body">
            <div class="row">
                <div class="col-md-6 mb-3">
                    <label class="form-label">Loan Amount</label>
                    <input type="number" class="form-control" name="loan_amount" min="1000" max="100000" required>
                </div>
                <div class="col-md-6 mb-3">
                    <label class="form-label">Term (Months)</label>
                    <select class="form-select" name="term_months" required>
                        <option value="">Select Term</option>
                        <option value="12">12 months</option>
                        <option value="24">24 months</option>
                        <option value="36">36 months</option>
                        <option value="48">48 months</option>
                        <option value="60">60 months</option>
                        <option value="72">72 months</option>
                    </select>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Vehicle Information -->
    <div class="card mb-3">
        <div class="card-header">
            <h6>Vehicle Information</h6>
        </div>
        <div class="card-body">
            <div class="row">
                <div class="col-md-6 mb-3">
                    <label class="form-label">Year</label>
                    <input type="number" class="form-control" name="vehicle_year" min="2000" max="2025" required>
                </div>
                <div class="col-md-6 mb-3">
                    <label class="form-label">Make</label>
                    <input type="text" class="form-control" name="vehicle_make" required>
                </div>
                <div class="col-md-6 mb-3">
                    <label class="form-label">Model</label>
                    <input type="text" class="form-control" name="vehicle_model" required>
                </div>
                <div class="col-md-6 mb-3">
                    <label class="form-label">VIN</label>
                    <input type="text" class="form-control" name="vin" pattern="[A-HJ-NPR-Z0-9]{17}" required>
                </div>
                <div class="col-md-6 mb-3">
                    <label class="form-label">Mileage</label>
                    <input type="number" class="form-control" name="mileage" min="0" max="500000" required>
                </div>
                <div class="col-md-6 mb-3">
                    <label class="form-label">Value</label>
                    <input type="number" class="form-control" name="vehicle_value" min="1000" max="200000" required>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Submit Button -->
    <div class="d-grid">
        <button type="submit" class="btn btn-success btn-lg">
            <i class="bi bi-send"></i> Submit Application
        </button>
    </div>
</form>
```

### Results Display
```html
<div class="card">
    <div class="card-header">
        <h5><i class="bi bi-clipboard-data"></i> Results</h5>
    </div>
    <div class="card-body">
        <!-- Status Indicator -->
        <div id="resultStatus" class="text-center mb-3" style="display: none;">
            <div class="spinner-border" role="status">
                <span class="visually-hidden">Processing...</span>
            </div>
            <p class="mt-2">Processing application...</p>
        </div>
        
        <!-- Decision Display -->
        <div id="decisionResult" style="display: none;">
            <div class="text-center mb-3">
                <div id="decisionBadge" class="badge fs-5 p-3">
                    <i id="decisionIcon"></i>
                    <span id="decisionText"></span>
                </div>
            </div>
            
            <!-- Score Breakdown -->
            <div class="mb-3">
                <h6>Score Breakdown</h6>
                <div class="mb-2">
                    <div class="d-flex justify-content-between">
                        <span>Rules Score</span>
                        <span id="rulesScore" class="badge bg-secondary">--</span>
                    </div>
                    <div class="progress mt-1" style="height: 8px;">
                        <div id="rulesProgress" class="progress-bar" style="width: 0%"></div>
                    </div>
                </div>
                <div class="mb-2">
                    <div class="d-flex justify-content-between">
                        <span>ML Confidence</span>
                        <span id="mlScore" class="badge bg-secondary">--</span>
                    </div>
                    <div class="progress mt-1" style="height: 8px;">
                        <div id="mlProgress" class="progress-bar" style="width: 0%"></div>
                    </div>
                </div>
                <div class="mb-2">
                    <div class="d-flex justify-content-between">
                        <span>Adjudicator Score</span>
                        <span id="adjudicatorScore" class="badge bg-secondary">--</span>
                    </div>
                    <div class="progress mt-1" style="height: 8px;">
                        <div id="adjudicatorProgress" class="progress-bar" style="width: 0%"></div>
                    </div>
                </div>
            </div>
            
            <!-- Explainability -->
            <div class="mb-3">
                <h6>Key Factors</h6>
                <div id="ruleFlags" class="mb-2"></div>
                <div id="topFeatures" class="mb-2"></div>
                <div id="adjudicatorRationale" class="mb-2"></div>
            </div>
            
            <!-- Timing Information -->
            <div class="mb-3">
                <h6>Processing Time</h6>
                <div class="d-flex justify-content-between">
                    <span>Total Time</span>
                    <span id="totalTime" class="badge bg-info">-- ms</span>
                </div>
            </div>
        </div>
    </div>
</div>
```

## JavaScript Implementation

### Core Functions
```javascript
// CSRF token for Laravel
const csrfToken = document.querySelector('meta[name="csrf-token"]').getAttribute('content');

// AI Test Data Generation
async function generateTestData() {
    const riskLevel = document.getElementById('riskLevel').value;
    const customPrompt = document.getElementById('customPrompt').value;
    const loadingElement = document.getElementById('generateLoading');
    
    try {
        loadingElement.style.display = 'block';
        
        const response = await fetch('/test-ui/generate-data', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': csrfToken
            },
            body: JSON.stringify({
                risk_level: riskLevel,
                custom_prompt: customPrompt
            })
        });
        
        if (!response.ok) {
            throw new Error(`HTTP ${response.status}: ${response.statusText}`);
        }
        
        const data = await response.json();
        populateForm(data.application_data);
        
        showToast('Test data generated successfully!', 'success');
        
    } catch (error) {
        console.error('Error generating test data:', error);
        showToast('Failed to generate test data: ' + error.message, 'error');
    } finally {
        loadingElement.style.display = 'none';
    }
}

// Populate form with generated data
function populateForm(data) {
    const form = document.getElementById('applicationForm');
    
    Object.keys(data).forEach(key => {
        const input = form.querySelector(`[name="${key}"]`);
        if (input) {
            input.value = data[key];
            input.classList.remove('is-invalid');
        }
    });
}

// Submit application for fraud detection
async function submitApplication(event) {
    event.preventDefault();
    
    const form = event.target;
    if (!form.checkValidity()) {
        form.classList.add('was-validated');
        return;
    }
    
    const formData = new FormData(form);
    const applicationData = Object.fromEntries(formData.entries());
    
    try {
        showResultStatus('Processing application...');
        
        const response = await fetch('/api/applications', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-API-Key': 'test-key', // Use test API key
                'X-Timestamp': Math.floor(Date.now() / 1000),
                'X-Nonce': generateNonce()
            },
            body: JSON.stringify({
                payload_version: '1.0',
                applicant: {
                    age: parseInt(applicationData.age),
                    sin: applicationData.sin,
                    province: applicationData.province,
                    postal_code: applicationData.postal_code
                },
                loan: {
                    amount: parseInt(applicationData.loan_amount),
                    term_months: parseInt(applicationData.term_months),
                    purpose: 'auto'
                },
                vehicle: {
                    year: parseInt(applicationData.vehicle_year),
                    make: applicationData.vehicle_make,
                    model: applicationData.vehicle_model,
                    vin: applicationData.vin,
                    mileage: parseInt(applicationData.mileage),
                    value: parseInt(applicationData.vehicle_value)
                }
            })
        });
        
        if (!response.ok) {
            throw new Error(`HTTP ${response.status}: ${response.statusText}`);
        }
        
        const result = await response.json();
        pollForResult(result.job_id);
        
    } catch (error) {
        console.error('Error submitting application:', error);
        showToast('Failed to submit application: ' + error.message, 'error');
        hideResultStatus();
    }
}

// Poll for fraud detection result
async function pollForResult(jobId) {
    const maxAttempts = 60; // 5 minutes with 5-second intervals
    let attempts = 0;
    
    const poll = async () => {
        try {
            const response = await fetch(`/api/decision/${jobId}`);
            
            if (!response.ok) {
                throw new Error(`HTTP ${response.status}: ${response.statusText}`);
            }
            
            const result = await response.json();
            
            if (result.status === 'decided') {
                displayResult(result.decision);
                return;
            }
            
            if (result.status === 'failed') {
                throw new Error('Processing failed');
            }
            
            attempts++;
            if (attempts < maxAttempts) {
                setTimeout(poll, 5000); // Poll every 5 seconds
            } else {
                throw new Error('Timeout waiting for result');
            }
            
        } catch (error) {
            console.error('Error polling for result:', error);
            showToast('Failed to get result: ' + error.message, 'error');
            hideResultStatus();
        }
    };
    
    poll();
}

// Display fraud detection result
function displayResult(decision) {
    hideResultStatus();
    
    const resultElement = document.getElementById('decisionResult');
    const decisionBadge = document.getElementById('decisionBadge');
    const decisionIcon = document.getElementById('decisionIcon');
    const decisionText = document.getElementById('decisionText');
    
    // Set decision display
    const decisionConfig = {
        approve: { class: 'bg-success', icon: 'bi-check-circle', text: 'APPROVED' },
        review: { class: 'bg-warning', icon: 'bi-exclamation-triangle', text: 'REVIEW' },
        decline: { class: 'bg-danger', icon: 'bi-x-circle', text: 'DECLINED' }
    };
    
    const config = decisionConfig[decision.final_decision];
    decisionBadge.className = `badge fs-5 p-3 ${config.class}`;
    decisionIcon.className = config.icon;
    decisionText.textContent = config.text;
    
    // Update score displays
    updateScoreDisplay('rules', decision.scores.rule_score);
    updateScoreDisplay('ml', decision.scores.confidence_score);
    updateScoreDisplay('adjudicator', decision.scores.adjudicator_score);
    
    // Display explainability
    displayExplainability(decision.explainability);
    
    // Display timing
    document.getElementById('totalTime').textContent = `${decision.timing.total_ms} ms`;
    
    resultElement.style.display = 'block';
}

// System health monitoring
async function refreshHealth() {
    try {
        const response = await fetch('/test-ui/system-health', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': csrfToken
            }
        });
        
        if (!response.ok) {
            throw new Error(`HTTP ${response.status}: ${response.statusText}`);
        }
        
        const health = await response.json();
        updateHealthDisplay(health);
        
    } catch (error) {
        console.error('Error checking system health:', error);
        showToast('Failed to check system health', 'error');
    }
}

// Update health status display
function updateHealthDisplay(health) {
    const components = ['laravel', 'ml', 'llm', 'db'];
    
    components.forEach(component => {
        const statusElement = document.getElementById(`${component}-status`);
        const status = health[component];
        
        if (status.healthy) {
            statusElement.className = 'badge bg-success';
            statusElement.textContent = 'Healthy';
        } else {
            statusElement.className = 'badge bg-danger';
            statusElement.textContent = 'Error';
        }
    });
    
    // Update performance metrics
    if (health.performance) {
        const queueProgress = document.getElementById('queue-progress');
        const queueCount = document.getElementById('queue-count');
        const avgResponseTime = document.getElementById('avg-response-time');
        
        const queuePercentage = Math.min((health.performance.queue_depth / 100) * 100, 100);
        queueProgress.style.width = `${queuePercentage}%`;
        queueCount.textContent = `${health.performance.queue_depth} jobs`;
        
        avgResponseTime.textContent = `${health.performance.avg_response_time} ms`;
    }
}

// Utility functions
function generateNonce() {
    return Math.random().toString(36).substring(2, 15) + 
           Math.random().toString(36).substring(2, 15);
}

function showToast(message, type) {
    // Implementation for toast notifications
    console.log(`${type.toUpperCase()}: ${message}`);
}

function showResultStatus(message) {
    const statusElement = document.getElementById('resultStatus');
    statusElement.querySelector('p').textContent = message;
    statusElement.style.display = 'block';
    document.getElementById('decisionResult').style.display = 'none';
}

function hideResultStatus() {
    document.getElementById('resultStatus').style.display = 'none';
}

// Initialize event listeners
document.addEventListener('DOMContentLoaded', function() {
    document.getElementById('applicationForm').addEventListener('submit', submitApplication);
    
    // Auto-refresh health every 30 seconds
    setInterval(refreshHealth, 30000);
    refreshHealth(); // Initial load
});
```

## Controller Implementation

### TestUIController.php
```php
<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Exception;

class TestUIController extends Controller
{
    public function index()
    {
        return view('test-ui');
    }
    
    public function generateTestData(Request $request)
    {
        $request->validate([
            'risk_level' => 'required|in:low,medium,high,invalid',
            'custom_prompt' => 'nullable|string|max:500'
        ]);
        
        try {
            $prompt = $this->buildTestDataPrompt(
                $request->risk_level,
                $request->custom_prompt
            );
            
            $response = Http::timeout(30)
                ->withHeaders([
                    'Authorization' => 'Bearer ' . config('services.openrouter.api_key'),
                    'Content-Type' => 'application/json'
                ])
                ->post('https://openrouter.ai/api/v1/chat/completions', [
                    'model' => 'anthropic/claude-3-sonnet',
                    'messages' => [
                        [
                            'role' => 'user',
                            'content' => $prompt
                        ]
                    ],
                    'max_tokens' => 1000,
                    'temperature' => 0.7
                ]);
            
            if (!$response->successful()) {
                throw new Exception('OpenRouter API request failed');
            }
            
            $aiResponse = $response->json();
            $generatedData = $this->parseAIResponse($aiResponse['choices'][0]['message']['content']);
            
            return response()->json([
                'success' => true,
                'application_data' => $generatedData
            ]);
            
        } catch (Exception $e) {
            Log::error('Test data generation failed', [
                'error' => $e->getMessage(),
                'risk_level' => $request->risk_level
            ]);
            
            return response()->json([
                'success' => false,
                'error' => 'Failed to generate test data'
            ], 500);
        }
    }
    
    public function systemHealth()
    {
        $health = [
            'laravel' => $this->checkLaravelHealth(),
            'ml' => $this->checkMLServiceHealth(),
            'llm' => $this->checkLLMHealth(),
            'db' => $this->checkDatabaseHealth(),
            'performance' => $this->getPerformanceMetrics()
        ];
        
        return response()->json($health);
    }
    
    private function buildTestDataPrompt(string $riskLevel, ?string $customPrompt): string
    {
        $basePrompt = "Generate realistic Canadian auto loan application data in JSON format. ";
        
        $riskPrompts = [
            'low' => "Create a low-risk applicant: stable employment, good credit, reasonable loan amount, matching geographic data.",
            'medium' => "Create a medium-risk applicant: some risk factors like higher LTV, shorter employment, or minor geographic inconsistencies.",
            'high' => "Create a high-risk applicant: multiple red flags like high LTV, unstable employment, geographic mismatches, or suspicious patterns.",
            'invalid' => "Create invalid data: missing required fields, invalid SIN checksum, impossible values, or malformed data."
        ];
        
        $prompt = $basePrompt . $riskPrompts[$riskLevel];
        
        if ($customPrompt) {
            $prompt .= " Additional requirements: " . $customPrompt;
        }
        
        $prompt .= "\n\nReturn only valid JSON with these fields: age, sin, province, postal_code, loan_amount, term_months, vehicle_year, vehicle_make, vehicle_model, vin, mileage, vehicle_value";
        
        return $prompt;
    }
    
    private function parseAIResponse(string $content): array
    {
        // Extract JSON from AI response
        preg_match('/\{.*\}/s', $content, $matches);
        
        if (empty($matches)) {
            throw new Exception('No valid JSON found in AI response');
        }
        
        $data = json_decode($matches[0], true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception('Invalid JSON in AI response');
        }
        
        return $data;

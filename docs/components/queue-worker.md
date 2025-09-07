# Queue Worker Component Plan

## Overview
Laravel queue worker that processes fraud detection jobs asynchronously, orchestrating the entire decision pipeline from rules evaluation to final decision assembly.

## Local Development Setup

### Prerequisites
- Laravel 12.28.1 with DB queue driver
- PostgreSQL for job storage
- Access to ML inference service
- Bedrock API credentials

### Installation
```bash
# Start queue worker
php artisan queue:work --queue=fraud-detection --tries=3 --timeout=300

# Monitor queue
php artisan queue:monitor fraud-detection

# Clear failed jobs
php artisan queue:flush
```

## Component Responsibilities

### 1. Job Processing Pipeline
The worker processes each fraud detection job through these stages:

1. **Load Request Data** - Retrieve raw application from database
2. **Rules Engine** - Apply deterministic rules and generate rule score
3. **Feature Engineering** - Extract Top-15 features for ML model
4. **ML Inference** - Get confidence score from LightGBM model
5. **LLM Adjudicator** - Get advisory score from Bedrock
6. **Decision Assembly** - Combine all scores into final decision
7. **Persist Results** - Store decision and metadata

### 2. Error Handling & Retries
- Exponential backoff for transient failures
- Dead letter queue for permanent failures
- Circuit breaker for external service failures
- Detailed error logging and alerting

### 3. Performance Optimization
- Concurrent worker processes (2-4 recommended)
- Batch processing where applicable
- Connection pooling for external services
- Memory management for long-running processes

## Processing Pipeline Details

### Stage 1: Rules Engine
```php
// Execute all rules and collect results
$rulesResult = $this->rulesEngine->evaluate($applicationData);

// Check for hard-fail conditions
if ($rulesResult->hasHardFails()) {
    return $this->createDecision('decline', $rulesResult);
}

// Calculate weighted rule score
$ruleScore = $rulesResult->calculateScore();
```

**Output:**
- `rule_flags[]` - Array of triggered rule names
- `rule_score` - Normalized score 0-1
- `rulepack_version` - Version of rules applied
- `hard_fail` - Boolean indicating immediate decline

### Stage 2: Feature Engineering
```php
// Extract Top-15 features
$features = $this->featureExtractor->extract($applicationData);

// Validate feature ranges and handle nulls
$validatedFeatures = $this->validateFeatures($features);
```

**Output:**
- `feature_vector` - Array of 15 normalized values
- `feature_set_version` - Version of feature definitions
- `validation_status` - Feature quality indicators

### Stage 3: ML Inference
```php
// Call ML service with features
$mlResponse = Http::timeout(30)
    ->post($this->mlServiceUrl . '/score', [
        'features' => $validatedFeatures,
        'model_version' => 'latest'
    ]);

$confidenceScore = $mlResponse->json('confidence_score');
$topFeatures = $mlResponse->json('top_features');
```

**Output:**
- `confidence_score` - Calibrated probability 0-1
- `top_features[]` - Most important features for this decision
- `model_version` - Version of ML model used
- `calibration_version` - Version of calibration applied

### Stage 4: LLM Adjudicator
```php
// Create redacted dossier (no PII)
$dossier = $this->createRedactedDossier($applicationData, $rulesResult, $mlResult);

// Call Bedrock with privacy-safe prompt
$adjudicatorResponse = $this->bedrockClient->invoke([
    'modelId' => 'anthropic.claude-3-haiku-20240307-v1:0',
    'contentType' => 'application/json',
    'body' => json_encode([
        'anthropic_version' => 'bedrock-2023-05-31',
        'max_tokens' => 200,
        'messages' => [['role' => 'user', 'content' => $prompt]]
    ])
]);
```

**Redacted Dossier Example:**
```json
{
  "case_id": "uuid",
  "age_band": "35-44",
  "province": "ON",
  "ltv_ratio": 0.89,
  "downpayment_income_ratio": 0.15,
  "rule_flags": ["province_ip_mismatch", "high_ltv"],
  "ml_confidence": 0.75,
  "top_ml_features": ["debt_to_income", "credit_score", "employment_length"],
  "dealer_risk_percentile": 0.65
}
```

**Output:**
- `adjudicator_score` - Advisory score 0-1
- `risk_band` - "low|medium|high"
- `rationale` - Brief explanation (≤3 bullet points)
- `adjudicator_model_id` - Bedrock model used

### Stage 5: Decision Assembly
```php
// Apply decision policy thresholds
$finalDecision = $this->decisionPolicy->evaluate([
    'rule_score' => $ruleScore,
    'confidence_score' => $confidenceScore,
    'adjudicator_score' => $adjudicatorScore,
    'hard_fails' => $rulesResult->hasHardFails()
]);
```

**Decision Logic:**
- If hard-fail rules triggered → `decline`
- If confidence_score ≥ 0.8 OR rule_score ≥ 0.7 → `review` or `decline`
- If adjudicator_score ≥ 0.75 → escalate to `review`
- Else → `approve`

## Database Schema

### Job Tracking Tables
```sql
-- jobs table (Laravel queue)
CREATE TABLE jobs (
    id BIGSERIAL PRIMARY KEY,
    queue VARCHAR(255) NOT NULL,
    payload TEXT NOT NULL,
    attempts SMALLINT NOT NULL,
    reserved_at INTEGER,
    available_at INTEGER NOT NULL,
    created_at INTEGER NOT NULL
);

-- failed_jobs table
CREATE TABLE failed_jobs (
    id BIGSERIAL PRIMARY KEY,
    uuid VARCHAR(255) UNIQUE NOT NULL,
    connection TEXT NOT NULL,
    queue TEXT NOT NULL,
    payload TEXT NOT NULL,
    exception TEXT NOT NULL,
    failed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
```

### Processing Results Tables
```sql
-- rules_outputs
CREATE TABLE rules_outputs (
    id BIGSERIAL PRIMARY KEY,
    request_id UUID NOT NULL,
    rule_flags JSONB,
    rule_score DECIMAL(5,4),
    rulepack_version VARCHAR(50),
    hard_fail BOOLEAN DEFAULT FALSE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- features
CREATE TABLE features (
    id BIGSERIAL PRIMARY KEY,
    request_id UUID NOT NULL,
    feature_vector JSONB,
    feature_set_version VARCHAR(50),
    validation_status JSONB,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- ml_outputs
CREATE TABLE ml_outputs (
    id BIGSERIAL PRIMARY KEY,
    request_id UUID NOT NULL,
    confidence_score DECIMAL(5,4),
    top_features JSONB,
    model_version VARCHAR(50),
    calibration_version VARCHAR(50),
    inference_time_ms INTEGER,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- adjudicator_outputs
CREATE TABLE adjudicator_outputs (
    id BIGSERIAL PRIMARY KEY,
    request_id UUID NOT NULL,
    adjudicator_score DECIMAL(5,4),
    risk_band VARCHAR(20),
    rationale TEXT,
    adjudicator_model_id VARCHAR(100),
    prompt_template_version VARCHAR(50),
    tokens_used INTEGER,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
```

## Implementation Files

### Core Worker
- `app/Jobs/FraudDetectionJob.php` - Main job class
- `app/Services/FraudDetectionPipeline.php` - Pipeline orchestrator

### Pipeline Stages
- `app/Services/RulesEngineService.php` - Rules evaluation
- `app/Services/FeatureExtractionService.php` - Feature engineering
- `app/Services/MLInferenceService.php` - ML model calls
- `app/Services/BedrockAdjudicatorService.php` - LLM adjudication
- `app/Services/DecisionAssemblyService.php` - Final decision logic

### Support Classes
- `app/Services/RedactionService.php` - PII removal for LLM
- `app/Services/VersionManager.php` - Component version tracking
- `app/Exceptions/PipelineException.php` - Custom exceptions

## Configuration

### Queue Settings
```php
// config/queue.php
'connections' => [
    'database' => [
        'driver' => 'database',
        'table' => 'jobs',
        'queue' => 'fraud-detection',
        'retry_after' => 300,
        'after_commit' => false,
    ],
],

// Worker configuration
'fraud-detection' => [
    'max_workers' => 4,
    'timeout' => 300,
    'max_tries' => 3,
    'backoff' => [10, 30, 90], // seconds
]
```

### External Service Timeouts
```php
// config/services.php
'ml_service' => [
    'url' => env('ML_SERVICE_URL', 'http://localhost:8000'),
    'timeout' => 30,
    'retry_attempts' => 2,
],

'bedrock' => [
    'region' => 'ca-central-1',
    'model_id' => 'anthropic.claude-3-haiku-20240307-v1:0',
    'max_tokens' => 200,
    'timeout' => 45,
]
```

## Monitoring & Alerting

### Key Metrics
- Queue depth and age of oldest job
- Processing time per stage
- Success/failure rates by stage
- External service response times
- Memory usage and worker health

### Health Checks
```php
// Queue health indicators
- jobs_pending: count of queued jobs
- jobs_processing: count of running jobs
- oldest_job_age: age of oldest pending job
- failed_jobs_24h: failed jobs in last 24 hours
- avg_processing_time: average end-to-end time
```

### Alerting Thresholds
- Queue depth > 100 jobs
- Oldest job > 5 minutes
- Processing time P95 > 2 minutes
- Failure rate > 5% over 15 minutes
- Worker memory usage > 80%

## Error Handling Strategy

### Retry Logic
```php
// Transient errors (network, timeout)
- Retry with exponential backoff
- Max 3 attempts
- Different backoff for different error types

// Permanent errors (validation, auth)
- No retry, immediate failure
- Log detailed error information
- Send to dead letter queue
```

### Circuit Breaker
```php
// External service protection
- Open circuit after 5 consecutive failures
- Half-open after 60 seconds
- Close circuit after 3 successful calls
```

## AWS Migration Notes

### Target Architecture
- **SQS** for job queue (replace DB queue)
- **Lambda** for worker functions (alternative to EC2)
- **Step Functions** for pipeline orchestration
- **CloudWatch** for monitoring and alerting
- **X-Ray** for distributed tracing

### Scaling Strategy
- Auto Scaling based on queue depth
- Reserved concurrency for Lambda workers
- Dead letter queues for failed jobs
- CloudWatch alarms for operational metrics

### Cost Optimization
- Use SQS FIFO queues for ordering
- Batch processing where possible
- Spot instances for non-critical workers
- Lambda for variable workloads

## Testing Strategy

### Unit Tests
- Individual pipeline stage logic
- Error handling scenarios
- Retry mechanisms
- Decision assembly logic

### Integration Tests
- End-to-end pipeline execution
- External service mocking
- Database transaction handling
- Queue processing simulation

### Load Tests
- Concurrent job processing
- Queue backlog scenarios
- External service failure simulation
- Memory leak detection

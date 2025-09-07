# Worker Pipeline Component

## Overview
The worker component processes fraud detection jobs through a sequential pipeline, orchestrating rules evaluation, feature engineering, ML scoring, LLM adjudication, and final decision assembly.

## Pipeline Architecture

### Main Orchestrator: FraudDetectionJob
The primary job that coordinates the entire fraud detection pipeline:

1. **Load Request Data** - Retrieve raw application from database
2. **Rules Processing** - Apply business rules and hard-fail checks
3. **Feature Engineering** - Extract and transform features
4. **ML Scoring** - Get machine learning confidence score
5. **LLM Adjudication** - Bedrock-based risk assessment
6. **Decision Assembly** - Combine scores into final decision
7. **Persistence** - Save results and update job status

### Pipeline Stages

#### Stage 1: Rules Processing
- **Input**: Raw application JSON
- **Process**: 
  - Hard-fail validation (SIN checksum, mandatory fields)
  - Risk flag evaluation (weighted scoring)
  - Short-circuit on critical failures
- **Output**: `rule_flags[]`, `rule_score`, `rulepack_version`
- **Duration**: ~100-500ms

#### Stage 2: Feature Engineering
- **Input**: Raw application + rules output
- **Process**: Extract Top-15 features for ML model
- **Output**: Feature vector + `feature_set_version`
- **Duration**: ~200-800ms

#### Stage 3: ML Scoring
- **Input**: Feature vector
- **Process**: Call LightGBM inference service
- **Output**: `confidence_score`, `top_features[]`, model versions
- **Duration**: ~300-1000ms

#### Stage 4: LLM Adjudication
- **Input**: Redacted dossier (no PII)
- **Process**: Bedrock API call with structured prompt
- **Output**: `adjudicator_score`, `risk_band`, `rationale`
- **Duration**: ~1000-3000ms

#### Stage 5: Decision Assembly
- **Input**: All previous scores and flags
- **Process**: Apply decision policy thresholds
- **Output**: `final_decision`, `reasons[]`, `policy_version`
- **Duration**: ~50-200ms

## Job Classes

### FraudDetectionJob (Main Orchestrator)
```php
class FraudDetectionJob implements ShouldQueue
{
    public $timeout = 300; // 5 minutes
    public $tries = 3;
    public $backoff = [30, 60, 120]; // Exponential backoff
    
    public function handle()
    {
        // Pipeline execution logic
    }
}
```

### RulesProcessingJob
- Evaluates business rules
- Handles hard-fail scenarios
- Calculates weighted rule score
- Records rule flags and versions

### FeatureEngineeringJob
- Extracts Top-15 features
- Validates feature ranges
- Handles missing data
- Records feature set version

### MLScoringJob
- Calls ML inference service
- Handles service timeouts/retries
- Records model and calibration versions
- Captures inference latency

### AdjudicatorJob
- Prepares redacted dossier
- Calls Bedrock API via VPC endpoint
- Parses LLM response
- Records prompt template version

### DecisionAssemblyJob
- Applies decision policy logic
- Combines all scores and flags
- Generates explanation reasons
- Records final decision

## Error Handling

### Retry Strategy
- **Transient Errors**: Network timeouts, service unavailable
- **Retry Count**: 3 attempts maximum
- **Backoff**: Exponential (30s, 60s, 120s)
- **Dead Letter Queue**: Failed jobs table

### Error Types
1. **Validation Errors**: Invalid input data (no retry)
2. **Service Errors**: ML service down, Bedrock unavailable (retry)
3. **Timeout Errors**: Long-running operations (retry with backoff)
4. **Critical Errors**: Database connection lost (retry)

### Failure Handling
```php
public function failed(Throwable $exception)
{
    // Log error details
    // Update job status to 'failed'
    // Send alerts if needed
    // Store in failed_jobs table
}
```

## Performance Optimization

### Concurrency
- **Workers**: 2-4 concurrent workers
- **Queue**: Single 'fraud-detection' queue
- **Batching**: Process jobs individually for now
- **Scaling**: Add workers based on queue depth

### Caching
- **Rules Cache**: Cache rule definitions (5 min TTL)
- **Feature Cache**: Cache computed features for duplicate requests
- **Model Cache**: ML service keeps model in memory
- **Config Cache**: Cache decision policy thresholds

### Monitoring
- **Queue Depth**: Alert if >50 jobs pending
- **Processing Time**: P95 target <5 minutes
- **Error Rate**: Alert if >5% failure rate
- **Stage Timing**: Track each pipeline stage duration

## Local Development Setup

### Environment Requirements
```bash
# PHP 8.2+ with extensions
php -m | grep -E "(pdo_pgsql|redis|curl|json)"

# Queue worker dependencies
composer install

# Environment configuration
cp .env.example .env.local
```

### Running Workers
```bash
# Single worker (development)
php artisan queue:work --queue=fraud-detection --timeout=300

# Multiple workers (production-like)
php artisan queue:work --queue=fraud-detection --timeout=300 &
php artisan queue:work --queue=fraud-detection --timeout=300 &

# Monitor queue status
php artisan queue:monitor fraud-detection
```

### Testing Pipeline
```bash
# Run pipeline tests
php artisan test --filter=FraudDetectionPipelineTest

# Test individual stages
php artisan test --filter=RulesProcessingTest
php artisan test --filter=FeatureEngineeringTest
```

## Configuration

### Queue Configuration
```php
// config/queue.php
'connections' => [
    'database' => [
        'driver' => 'database',
        'table' => 'jobs',
        'queue' => 'fraud-detection',
        'retry_after' => 300,
    ],
],
```

### Pipeline Configuration
```php
// config/fraud-detection.php
'pipeline' => [
    'timeout' => 300,
    'max_retries' => 3,
    'stages' => [
        'rules' => ['timeout' => 30],
        'features' => ['timeout' => 60],
        'ml_scoring' => ['timeout' => 90],
        'adjudication' => ['timeout' => 120],
        'decision' => ['timeout' => 30],
    ],
],
```

## Logging & Observability

### Structured Logging
```php
Log::info('Pipeline stage completed', [
    'job_id' => $this->jobId,
    'stage' => 'rules_processing',
    'duration_ms' => $duration,
    'rule_score' => $ruleScore,
    'flags_triggered' => count($ruleFlags),
]);
```

### Metrics Collection
- Stage completion times
- Error rates by stage
- Queue processing rates
- Resource utilization

### Health Checks
- Worker process health
- Database connectivity
- External service availability
- Queue processing capacity

## Security Considerations

### Data Handling
- No PII in logs
- Secure data transmission
- Encrypted sensitive fields
- Audit trail maintenance

### Service Communication
- TLS for all external calls
- API key authentication
- VPC endpoints for AWS services
- Network security groups

### Error Information
- Sanitized error messages
- No sensitive data in exceptions
- Secure error reporting
- Compliance with data protection

# Database & Queue Component

## Overview
PostgreSQL database serves as the system of record, while Laravel's DB queue driver handles asynchronous job processing for the fraud detection pipeline.

## Database Schema

### Core Tables

#### requests
- `request_id` (UUID, PK)
- `tenant_id` (UUID, FK)
- `raw_json` (JSONB) - Original application payload
- `payload_version` (VARCHAR) - Schema version
- `client_ip` (INET)
- `user_agent` (TEXT)
- `received_at` (TIMESTAMP)
- `created_at` (TIMESTAMP)
- `updated_at` (TIMESTAMP)

#### jobs (Laravel Queue)
- `id` (BIGINT, PK)
- `queue` (VARCHAR)
- `payload` (LONGTEXT) - Serialized job data
- `attempts` (TINYINT)
- `reserved_at` (INT)
- `available_at` (INT)
- `created_at` (INT)

#### failed_jobs (Laravel Queue)
- `id` (BIGINT, PK)
- `uuid` (VARCHAR)
- `connection` (TEXT)
- `queue` (TEXT)
- `payload` (LONGTEXT)
- `exception` (LONGTEXT)
- `failed_at` (TIMESTAMP)

### Processing Results Tables

#### rules_outputs
- `request_id` (UUID, FK)
- `rule_flags` (JSONB) - Array of triggered rules
- `rule_score` (DECIMAL) - Normalized 0-1 score
- `rulepack_version` (VARCHAR)
- `processed_at` (TIMESTAMP)

#### features
- `request_id` (UUID, FK)
- `feature_set_version` (VARCHAR)
- `vector_json` (JSONB) - Feature vector
- `validation_status` (VARCHAR)
- `processed_at` (TIMESTAMP)

#### ml_outputs
- `request_id` (UUID, FK)
- `confidence_score` (DECIMAL) - 0-1 probability
- `top_features` (JSONB) - Most important features
- `model_version` (VARCHAR)
- `calibration_version` (VARCHAR)
- `ml_latency_ms` (INT)
- `processed_at` (TIMESTAMP)

#### adjudicator_outputs
- `request_id` (UUID, FK)
- `adjudicator_score` (DECIMAL) - 0-1 score
- `risk_band` (VARCHAR) - low|medium|high
- `rationale` (TEXT) - LLM explanation
- `adjudicator_model_id` (VARCHAR)
- `prompt_template_version` (VARCHAR)
- `processed_at` (TIMESTAMP)

#### decisions
- `job_id` (UUID, FK)
- `request_id` (UUID, FK)
- `final_decision` (VARCHAR) - approve|review|decline
- `reasons` (JSONB) - Array of decision factors
- `policy_version` (VARCHAR)
- `received_at` (TIMESTAMP)
- `queued_at` (TIMESTAMP)
- `started_at` (TIMESTAMP)
- `ml_scored_at` (TIMESTAMP)
- `adjudicated_at` (TIMESTAMP)
- `decided_at` (TIMESTAMP)
- `total_ms` (INT)

### Configuration Tables

#### api_clients
- `client_id` (UUID, PK)
- `tenant_id` (UUID, FK)
- `api_key` (VARCHAR)
- `hmac_secret` (VARCHAR, encrypted)
- `is_active` (BOOLEAN)
- `rate_limit_per_minute` (INT)
- `created_at` (TIMESTAMP)

#### replay_nonces
- `nonce` (VARCHAR, PK)
- `client_id` (UUID, FK)
- `used_at` (TIMESTAMP)
- `expires_at` (TIMESTAMP)

## Queue Configuration

### Laravel DB Queue Driver
- **Driver**: database
- **Table**: jobs
- **Connection**: PostgreSQL
- **Retry Logic**: 3 attempts with exponential backoff
- **Timeout**: 300 seconds per job
- **Concurrency**: 2-4 workers

### Job Processing Pipeline
1. **FraudDetectionJob** - Main orchestrator
2. **RulesProcessingJob** - Rules evaluation
3. **FeatureEngineeringJob** - Feature extraction
4. **MLScoringJob** - Machine learning inference
5. **AdjudicatorJob** - LLM adjudication
6. **DecisionAssemblyJob** - Final decision logic

### Queue Monitoring
- Queue depth tracking
- Oldest job age alerts
- Failed job monitoring
- Worker health checks

## Indexing Strategy

### Performance Indexes
```sql
-- Primary lookups
CREATE INDEX idx_requests_tenant_received ON requests(tenant_id, received_at);
CREATE INDEX idx_decisions_job_id ON decisions(job_id);
CREATE INDEX idx_decisions_request_id ON decisions(request_id);

-- Time-based queries
CREATE INDEX idx_decisions_decided_at ON decisions(decided_at);
CREATE INDEX idx_jobs_available_at ON jobs(available_at);

-- Replay protection
CREATE INDEX idx_replay_nonces_expires ON replay_nonces(expires_at);
```

## Local Development Setup

### Database Setup
```bash
# PostgreSQL installation
sudo apt-get install postgresql postgresql-contrib

# Create database
createdb fraud_detector_dev

# Run migrations
php artisan migrate

# Seed test data
php artisan db:seed
```

### Queue Worker
```bash
# Start queue worker
php artisan queue:work --queue=fraud-detection --tries=3

# Monitor queue
php artisan queue:monitor
```

## Configuration Files
- `config/database.php` - Database connections
- `config/queue.php` - Queue driver configuration
- `database/migrations/` - Schema definitions
- `database/seeders/` - Test data

## Backup & Recovery
- Daily automated backups
- Point-in-time recovery capability
- Transaction log shipping
- Backup verification procedures

## Performance Considerations
- Connection pooling
- Query optimization
- Index maintenance
- Partition strategy for large tables
- Archive old data strategy

## Security
- Encrypted connections (SSL/TLS)
- Database user permissions
- Sensitive data encryption at rest
- Audit logging
- Regular security updates

# Database Layer Component Plan

## Overview
PostgreSQL-based data persistence layer that serves as the system of record for all fraud detection requests, processing stages, and decisions. Designed for ACID compliance, auditability, and performance.

## Local Development Setup

### Prerequisites
- PostgreSQL 14+
- Laravel 12.28.1 with Eloquent ORM
- Database migrations and seeders

### Installation
```bash
# Create database
createdb fraud_detector_dev

# Run migrations
php artisan migrate

# Seed test data (optional)
php artisan db:seed --class=FraudDetectionSeeder

# Create indexes for performance
php artisan db:index:create
```

## Component Responsibilities

### 1. Data Persistence
- Store raw application requests with full audit trail
- Track job processing states and timing
- Persist all pipeline stage outputs
- Maintain decision history and lineage

### 2. Data Integrity
- ACID transaction support
- Foreign key constraints
- Data validation at database level
- Backup and recovery procedures

### 3. Performance Optimization
- Strategic indexing for query patterns
- Connection pooling
- Query optimization
- Partitioning for large tables

## Database Schema Design

### Core Entities

#### 1. Requests Table
```sql
CREATE TABLE requests (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    tenant_id UUID NOT NULL,
    client_request_id VARCHAR(255), -- For idempotency
    payload JSONB NOT NULL,
    payload_version VARCHAR(50) NOT NULL,
    ip_address INET,
    user_agent TEXT,
    received_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    created_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    
    -- Indexes
    UNIQUE(tenant_id, client_request_id),
    INDEX idx_requests_tenant_received (tenant_id, received_at),
    INDEX idx_requests_payload_gin (payload) USING GIN
);
```

#### 2. Jobs Table (Laravel Queue)
```sql
CREATE TABLE jobs (
    id BIGSERIAL PRIMARY KEY,
    queue VARCHAR(255) NOT NULL,
    payload TEXT NOT NULL,
    attempts SMALLINT NOT NULL DEFAULT 0,
    reserved_at INTEGER,
    available_at INTEGER NOT NULL,
    created_at INTEGER NOT NULL,
    
    -- Indexes
    INDEX idx_jobs_queue_reserved (queue, reserved_at),
    INDEX idx_jobs_queue_available (queue, available_at)
);

CREATE TABLE failed_jobs (
    id BIGSERIAL PRIMARY KEY,
    uuid VARCHAR(255) UNIQUE NOT NULL,
    connection TEXT NOT NULL,
    queue TEXT NOT NULL,
    payload TEXT NOT NULL,
    exception TEXT NOT NULL,
    failed_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP
);
```

#### 3. Processing Pipeline Tables

```sql
-- Rules Engine Outputs
CREATE TABLE rules_outputs (
    id BIGSERIAL PRIMARY KEY,
    request_id UUID NOT NULL REFERENCES requests(id) ON DELETE CASCADE,
    rule_flags JSONB,
    rule_score DECIMAL(5,4) CHECK (rule_score >= 0 AND rule_score <= 1),
    rulepack_version VARCHAR(50) NOT NULL,
    hard_fail BOOLEAN DEFAULT FALSE,
    processing_time_ms INTEGER,
    created_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    
    -- Indexes
    INDEX idx_rules_outputs_request (request_id),
    INDEX idx_rules_outputs_score (rule_score),
    INDEX idx_rules_outputs_flags (rule_flags) USING GIN
);

-- Feature Engineering Outputs
CREATE TABLE features (
    id BIGSERIAL PRIMARY KEY,
    request_id UUID NOT NULL REFERENCES requests(id) ON DELETE CASCADE,
    feature_vector JSONB NOT NULL,
    feature_set_version VARCHAR(50) NOT NULL,
    validation_status JSONB,
    feature_names JSONB, -- Array of feature names for interpretability
    processing_time_ms INTEGER,
    created_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    
    -- Indexes
    INDEX idx_features_request (request_id),
    INDEX idx_features_version (feature_set_version),
    INDEX idx_features_vector (feature_vector) USING GIN
);

-- ML Model Outputs
CREATE TABLE ml_outputs (
    id BIGSERIAL PRIMARY KEY,
    request_id UUID NOT NULL REFERENCES requests(id) ON DELETE CASCADE,
    confidence_score DECIMAL(5,4) CHECK (confidence_score >= 0 AND confidence_score <= 1),
    top_features JSONB, -- Array of most important features
    model_version VARCHAR(50) NOT NULL,
    calibration_version VARCHAR(50),
    inference_time_ms INTEGER,
    model_metadata JSONB, -- Additional model info
    created_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    
    -- Indexes
    INDEX idx_ml_outputs_request (request_id),
    INDEX idx_ml_outputs_score (confidence_score),
    INDEX idx_ml_outputs_version (model_version)
);

-- LLM Adjudicator Outputs
CREATE TABLE adjudicator_outputs (
    id BIGSERIAL PRIMARY KEY,
    request_id UUID NOT NULL REFERENCES requests(id) ON DELETE CASCADE,
    adjudicator_score DECIMAL(5,4) CHECK (adjudicator_score >= 0 AND adjudicator_score <= 1),
    risk_band VARCHAR(20) CHECK (risk_band IN ('low', 'medium', 'high')),
    rationale TEXT,
    adjudicator_model_id VARCHAR(100) NOT NULL,
    prompt_template_version VARCHAR(50) NOT NULL,
    prompt_hash VARCHAR(64), -- SHA-256 of actual prompt for audit
    tokens_used INTEGER,
    processing_time_ms INTEGER,
    created_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    
    -- Indexes
    INDEX idx_adjudicator_outputs_request (request_id),
    INDEX idx_adjudicator_outputs_score (adjudicator_score),
    INDEX idx_adjudicator_outputs_model (adjudicator_model_id)
);
```

#### 4. Final Decisions Table
```sql
CREATE TABLE decisions (
    id BIGSERIAL PRIMARY KEY,
    job_id BIGINT, -- Reference to jobs table
    request_id UUID NOT NULL REFERENCES requests(id) ON DELETE CASCADE,
    final_decision VARCHAR(20) NOT NULL CHECK (final_decision IN ('approve', 'review', 'decline')),
    reasons JSONB, -- Array of reason codes/explanations
    policy_version VARCHAR(50) NOT NULL,
    
    -- Timing information
    received_at TIMESTAMP WITH TIME ZONE,
    queued_at TIMESTAMP WITH TIME ZONE,
    started_at TIMESTAMP WITH TIME ZONE,
    rules_completed_at TIMESTAMP WITH TIME ZONE,
    features_completed_at TIMESTAMP WITH TIME ZONE,
    ml_completed_at TIMESTAMP WITH TIME ZONE,
    adjudicator_completed_at TIMESTAMP WITH TIME ZONE,
    decided_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    total_processing_ms INTEGER,
    
    created_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    
    -- Indexes
    UNIQUE(request_id),
    INDEX idx_decisions_job (job_id),
    INDEX idx_decisions_final (final_decision),
    INDEX idx_decisions_decided (decided_at),
    INDEX idx_decisions_timing (total_processing_ms)
);
```

#### 5. System Management Tables

```sql
-- API Clients and Authentication
CREATE TABLE api_clients (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    tenant_id UUID NOT NULL,
    name VARCHAR(255) NOT NULL,
    api_key VARCHAR(255) UNIQUE NOT NULL,
    secret_key VARCHAR(255) NOT NULL, -- For HMAC
    is_active BOOLEAN DEFAULT TRUE,
    rate_limit_per_minute INTEGER DEFAULT 100,
    allowed_ips JSONB, -- Array of allowed IP addresses
    created_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    
    -- Indexes
    INDEX idx_api_clients_tenant (tenant_id),
    INDEX idx_api_clients_key (api_key),
    INDEX idx_api_clients_active (is_active)
);

-- Replay Attack Prevention
CREATE TABLE replay_nonces (
    id BIGSERIAL PRIMARY KEY,
    nonce VARCHAR(255) NOT NULL,
    api_client_id UUID NOT NULL REFERENCES api_clients(id),
    timestamp_used BIGINT NOT NULL,
    created_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    
    -- Indexes
    UNIQUE(nonce, api_client_id),
    INDEX idx_replay_nonces_timestamp (timestamp_used),
    INDEX idx_replay_nonces_client (api_client_id)
);

-- Component Version Tracking
CREATE TABLE component_versions (
    id BIGSERIAL PRIMARY KEY,
    component_name VARCHAR(100) NOT NULL,
    version VARCHAR(50) NOT NULL,
    description TEXT,
    config JSONB,
    is_active BOOLEAN DEFAULT TRUE,
    deployed_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    
    -- Indexes
    UNIQUE(component_name, version),
    INDEX idx_component_versions_active (component_name, is_active)
);
```

## Data Access Patterns

### 1. Request Submission Flow
```sql
-- Insert new request (with idempotency check)
INSERT INTO requests (tenant_id, client_request_id, payload, payload_version, ip_address)
VALUES ($1, $2, $3, $4, $5)
ON CONFLICT (tenant_id, client_request_id) 
DO NOTHING
RETURNING id;

-- Create job for processing
INSERT INTO jobs (queue, payload, available_at, created_at)
VALUES ('fraud-detection', $1, $2, $3);
```

### 2. Pipeline Processing Queries
```sql
-- Store rules output
INSERT INTO rules_outputs (request_id, rule_flags, rule_score, rulepack_version, hard_fail)
VALUES ($1, $2, $3, $4, $5);

-- Store features
INSERT INTO features (request_id, feature_vector, feature_set_version, validation_status)
VALUES ($1, $2, $3, $4);

-- Store ML output
INSERT INTO ml_outputs (request_id, confidence_score, top_features, model_version)
VALUES ($1, $2, $3, $4);

-- Store adjudicator output
INSERT INTO adjudicator_outputs (request_id, adjudicator_score, risk_band, rationale, adjudicator_model_id)
VALUES ($1, $2, $3, $4, $5);

-- Store final decision
INSERT INTO decisions (request_id, final_decision, reasons, policy_version, decided_at, total_processing_ms)
VALUES ($1, $2, $3, $4, $5, $6);
```

### 3. Decision Retrieval
```sql
-- Get complete decision with all pipeline data
SELECT 
    d.*,
    r.payload as request_data,
    ro.rule_flags, ro.rule_score, ro.rulepack_version,
    f.feature_vector, f.feature_set_version,
    ml.confidence_score, ml.top_features, ml.model_version,
    ao.adjudicator_score, ao.risk_band, ao.rationale, ao.adjudicator_model_id
FROM decisions d
JOIN requests r ON d.request_id = r.id
LEFT JOIN rules_outputs ro ON d.request_id = ro.request_id
LEFT JOIN features f ON d.request_id = f.request_id
LEFT JOIN ml_outputs ml ON d.request_id = ml.request_id
LEFT JOIN adjudicator_outputs ao ON d.request_id = ao.request_id
WHERE d.request_id = $1;
```

## Performance Optimization

### 1. Indexing Strategy
```sql
-- Composite indexes for common query patterns
CREATE INDEX idx_requests_tenant_time ON requests (tenant_id, received_at DESC);
CREATE INDEX idx_decisions_status_time ON decisions (final_decision, decided_at DESC);
CREATE INDEX idx_rules_score_time ON rules_outputs (rule_score DESC, created_at DESC);

-- Partial indexes for active records
CREATE INDEX idx_api_clients_active ON api_clients (tenant_id) WHERE is_active = true;

-- GIN indexes for JSONB columns
CREATE INDEX idx_requests_payload_gin ON requests USING GIN (payload);
CREATE INDEX idx_features_vector_gin ON features USING GIN (feature_vector);
```

### 2. Partitioning Strategy
```sql
-- Partition large tables by date for better performance
CREATE TABLE requests_y2025m01 PARTITION OF requests
FOR VALUES FROM ('2025-01-01') TO ('2025-02-01');

CREATE TABLE requests_y2025m02 PARTITION OF requests
FOR VALUES FROM ('2025-02-01') TO ('2025-03-01');
```

### 3. Connection Pooling
```php
// config/database.php
'pgsql' => [
    'driver' => 'pgsql',
    'host' => env('DB_HOST', '127.0.0.1'),
    'port' => env('DB_PORT', '5432'),
    'database' => env('DB_DATABASE', 'fraud_detector'),
    'username' => env('DB_USERNAME', 'forge'),
    'password' => env('DB_PASSWORD', ''),
    'charset' => 'utf8',
    'prefix' => '',
    'prefix_indexes' => true,
    'search_path' => 'public',
    'sslmode' => 'prefer',
    'options' => [
        PDO::ATTR_PERSISTENT => true,
        PDO::ATTR_EMULATE_PREPARES => false,
    ],
    'pool' => [
        'min_connections' => 5,
        'max_connections' => 20,
        'acquire_timeout' => 30,
    ],
],
```

## Data Retention & Archival

### 1. Retention Policies
```sql
-- Archive old requests (keep 2 years)
CREATE OR REPLACE FUNCTION archive_old_requests()
RETURNS void AS $$
BEGIN
    -- Move to archive table
    INSERT INTO requests_archive 
    SELECT * FROM requests 
    WHERE received_at < NOW() - INTERVAL '2 years';
    
    -- Delete from main table
    DELETE FROM requests 
    WHERE received_at < NOW() - INTERVAL '2 years';
END;
$$ LANGUAGE plpgsql;

-- Schedule via cron or Laravel scheduler
SELECT cron.schedule('archive-requests', '0 2 * * 0', 'SELECT archive_old_requests();');
```

### 2. Cleanup Procedures
```sql
-- Clean up old nonces (keep 24 hours)
DELETE FROM replay_nonces 
WHERE created_at < NOW() - INTERVAL '24 hours';

-- Clean up old failed jobs (keep 30 days)
DELETE FROM failed_jobs 
WHERE failed_at < NOW() - INTERVAL '30 days';
```

## Backup & Recovery

### 1. Backup Strategy
```bash
# Daily full backup
pg_dump -h localhost -U postgres -d fraud_detector \
  --format=custom --compress=9 \
  --file="/backups/fraud_detector_$(date +%Y%m%d).dump"

# Continuous WAL archiving for point-in-time recovery
archive_command = 'cp %p /backup/wal_archive/%f'
```

### 2. Recovery Procedures
```bash
# Point-in-time recovery
pg_basebackup -h localhost -D /recovery/base -U postgres -v -P -W
# Restore to specific timestamp
recovery_target_time = '2025-01-01 12:00:00'
```

## Monitoring & Health Checks

### 1. Database Health Metrics
```sql
-- Connection count
SELECT count(*) as active_connections 
FROM pg_stat_activity 
WHERE state = 'active';

-- Table sizes
SELECT 
    schemaname,
    tablename,
    pg_size_pretty(pg_total_relation_size(schemaname||'.'||tablename)) as size
FROM pg_tables 
WHERE schemaname = 'public'
ORDER BY pg_total_relation_size(schemaname||'.'||tablename) DESC;

-- Index usage
SELECT 
    schemaname,
    tablename,
    indexname,
    idx_scan,
    idx_tup_read,
    idx_tup_fetch
FROM pg_stat_user_indexes
ORDER BY idx_scan DESC;
```

### 2. Performance Monitoring
```sql
-- Slow queries
SELECT 
    query,
    calls,
    total_time,
    mean_time,
    rows
FROM pg_stat_statements
ORDER BY mean_time DESC
LIMIT 10;

-- Lock monitoring
SELECT 
    blocked_locks.pid AS blocked_pid,
    blocked_activity.usename AS blocked_user,
    blocking_locks.pid AS blocking_pid,
    blocking_activity.usename AS blocking_user,
    blocked_activity.query AS blocked_statement,
    blocking_activity.query AS current_statement_in_blocking_process
FROM pg_catalog.pg_locks blocked_locks
JOIN pg_catalog.pg_stat_activity blocked_activity ON blocked_activity.pid = blocked_locks.pid
JOIN pg_catalog.pg_locks blocking_locks ON blocking_locks.locktype = blocked_locks.locktype
JOIN pg_catalog.pg_stat_activity blocking_activity ON blocking_activity.pid = blocking_locks.pid
WHERE NOT blocked_locks.granted;
```

## AWS Migration Notes

### Target Architecture
- **RDS PostgreSQL** Multi-AZ for high availability
- **Aurora PostgreSQL** for better scaling (alternative)
- **RDS Proxy** for connection pooling
- **CloudWatch** for monitoring
- **Parameter Store** for configuration

### Security Considerations
- Encryption at rest with KMS
- Encryption in transit with SSL
- VPC security groups
- IAM database authentication
- Secrets Manager for credentials

### Scaling Strategy
- Read replicas for read-heavy workloads
- Aurora Auto Scaling for variable loads
- Cross-region replicas for disaster recovery
- Automated backups with point-in-time recovery

## Implementation Files

### Models
- `app/Models/FraudRequest.php` - Main request model
- `app/Models/Decision.php` - Decision model with relationships
- `app/Models/RulesOutput.php` - Rules engine results
- `app/Models/Feature.php` - Feature engineering results
- `app/Models/MLOutput.php` - ML inference results
- `app/Models/AdjudicatorOutput.php` - LLM adjudicator results
- `app/Models/ApiClient.php` - API client management

### Migrations
- `database/migrations/create_requests_table.php`
- `database/migrations/create_decisions_table.php`
- `database/migrations/create_pipeline_outputs_tables.php`
- `database/migrations/create_api_clients_table.php`
- `database/migrations/create_indexes.php`

### Seeders
- `database/seeders/ApiClientSeeder.php`
- `database/seeders/ComponentVersionSeeder.php`
- `database/seeders/TestDataSeeder.php`

## Testing Strategy

### Unit Tests
- Model relationships and validations
- Database constraints
- Query performance
- Data integrity

### Integration Tests
- End-to-end data flow
- Transaction handling
- Concurrent access scenarios
- Backup and recovery procedures

### Performance Tests
- Query performance under load
- Connection pool behavior
- Index effectiveness
- Large dataset handling
